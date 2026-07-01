<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * High-visibility KPI strip at the top of the dashboard.
 *
 * Counts respect the multi-tenant RepositoryScope automatically (because the
 * underlying models use the BelongsToRepository trait). super_admin / admin
 * users bypass the scope and see every repository.
 *
 * All counts are cached for 5 minutes to keep the dashboard cheap on reload.
 * The cache key includes the user id (or "guest") and the user's effective
 * repository ids, so a tenant switch never shows stale numbers.
 */
class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    /** Render this widget first — it's the headline. */
    protected static ?int $sort = 1;

    protected ?string $heading = 'Overview';

    protected function getStats(): array
    {
        $stats = Cache::remember(
            $this->cacheKey(),
            now()->addMinutes(5),
            fn (): array => $this->computeStats(),
        );

        $cards = [
            Stat::make('Documents', number_format($stats['documents_total']))
                ->description($stats['documents_last_7'] . ' added in the last 7 days')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary')
                ->chart($stats['documents_chart']),

            Stat::make('Active Batches', number_format($stats['batches_active']))
                ->description('of ' . number_format($stats['batches_total']) . ' total')
                ->descriptionIcon('heroicon-m-archive-box')
                ->color('info'),

            Stat::make('Boxes in archive', number_format($stats['boxes_in']))
                ->description('barcode status IN')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success'),

            Stat::make('Authorities (Notaries)', number_format($stats['authorities_total']))
                ->description('Creators on file')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('gray'),

            Stat::make('Pending disinfestation', number_format($stats['pending_disinfestation']))
                ->description($stats['pending_disinfestation'] > 0
                    ? 'documents awaiting fumigation'
                    : 'all caught up')
                ->descriptionIcon($stats['pending_disinfestation'] > 0
                    ? 'heroicon-m-exclamation-triangle'
                    : 'heroicon-m-check-circle')
                ->color($stats['pending_disinfestation'] > 0 ? 'warning' : 'success'),

            // RFQ §3.1.12 — issue flags replace spreadsheet colour-coding.
            // Card colour is `danger` if any critical flag is open, `warning`
            // if there are open flags but none are critical, `success` when
            // the inbox is empty.
            Stat::make('Open flags', number_format($stats['open_flags_total']))
                ->description($stats['open_flags_critical'] > 0
                    ? $stats['open_flags_critical'] . ' critical'
                    : 'none critical')
                ->descriptionIcon('heroicon-m-flag')
                ->color($stats['open_flags_critical'] > 0
                    ? 'danger'
                    : ($stats['open_flags_total'] > 0 ? 'warning' : 'success')),
        ];

        if ($this->shouldShowRepositoryCard()) {
            $cards[] = Stat::make('Repositories', number_format($stats['repositories_total']))
                ->description('tenants')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('gray');
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeStats(): array
    {
        // Documents: total + 7-day trend
        $documentsTotal = Document::query()->count();
        $documentsLast7 = Document::query()
            ->where('created_at', '>=', now()->subDays(7)->startOfDay())
            ->count();

        // Chart: documents created per day for last 7 days (oldest → newest).
        $chart = [];
        $start = CarbonImmutable::now()->subDays(6)->startOfDay();
        $rows = Document::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();
        for ($i = 0; $i < 7; $i++) {
            $day = $start->addDays($i)->toDateString();
            $chart[] = (int) ($rows[$day] ?? 0);
        }

        // Authorities — global reference, no repository scope on the model
        $authoritiesTotal = Authority::query()->count();

        // Batches — active = has at least 1 box
        $batchesTotal = Batch::query()->count();
        $batchesActive = Batch::query()->whereHas('boxes')->count();

        // Pending disinfestation: disinfestation_date NULL AND
        // (current box is null OR current box is NOT PERM_OUT).
        // PERM_OUT documents are off the floor — no fumigation needed.
        $pendingDisinfestation = Document::query()
            ->whereNull('disinfestation_date')
            ->where(function ($q): void {
                $q->whereNull('current_box_id')
                    ->orWhereHas('currentBox', function ($q): void {
                        $q->where('barcode_status', '!=', 'PERM_OUT');
                    });
            })
            ->count();

        // Boxes in archive (Box has no repository_id — restrict via batch.repository_id
        // for non-admin users by joining batches and intersecting with allowed repos).
        $boxesIn = $this->countBoxesInRespectingScope();

        // Repositories — count is global (Repository does NOT use BelongsToRepository,
        // it IS the tenant). For non-admin we just count their attached repos.
        $repositoriesTotal = $this->countRepositoriesForUser();

        // RFQ §3.1.12 — open issue flags (= "actionable inbox"). The
        // RepositoryScope on DocumentFlag already restricts non-admin reads
        // to the user's tenants, so the count is automatically tenant-correct.
        $openFlagsTotal = DocumentFlag::query()->open()->count();
        $openFlagsCritical = DocumentFlag::query()
            ->open()
            ->where('severity', 'critical')
            ->count();

        return [
            'documents_total' => $documentsTotal,
            'documents_last_7' => $documentsLast7,
            'documents_chart' => $chart,
            'authorities_total' => $authoritiesTotal,
            'batches_total' => $batchesTotal,
            'batches_active' => $batchesActive,
            'pending_disinfestation' => $pendingDisinfestation,
            'boxes_in' => $boxesIn,
            'repositories_total' => $repositoriesTotal,
            'open_flags_total' => $openFlagsTotal,
            'open_flags_critical' => $openFlagsCritical,
        ];
    }

    /**
     * Box has no `repository_id`. For admin users count all IN boxes; for tenant
     * users restrict to boxes whose batch belongs to one of their repositories.
     */
    protected function countBoxesInRespectingScope(): int
    {
        $user = Auth::user();

        if ($this->userIsAdmin($user)) {
            return Box::query()->where('barcode_status', 'IN')->count();
        }

        $allowed = $this->allowedRepositoryIds($user);
        if (empty($allowed)) {
            return 0;
        }

        return Box::query()
            ->where('barcode_status', 'IN')
            ->whereHas('batch', fn ($q) => $q->whereIn('repository_id', $allowed))
            ->count();
    }

    protected function countRepositoriesForUser(): int
    {
        $user = Auth::user();
        if ($this->userIsAdmin($user)) {
            return Repository::query()->count();
        }

        return count($this->allowedRepositoryIds($user));
    }

    protected function shouldShowRepositoryCard(): bool
    {
        return $this->userIsAdmin(Auth::user());
    }

    /** @return array<int, int> */
    protected function allowedRepositoryIds(?object $user): array
    {
        if (! $user) {
            return [];
        }
        $ids = collect();
        if (method_exists($user, 'repositories')) {
            $ids = $user->repositories()->pluck('repositories.id');
        }
        if (! empty($user->default_repository_id)) {
            $ids = $ids->push($user->default_repository_id);
        }

        return $ids->unique()->values()->all();
    }

    protected function userIsAdmin(?object $user): bool
    {
        return $user
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['super_admin', 'admin']);
    }

    protected function cacheKey(): string
    {
        $user = Auth::user();
        $uid = $user?->getKey() ?? 'guest';
        $ids = implode(',', $this->allowedRepositoryIds($user));
        $admin = $this->userIsAdmin($user) ? '1' : '0';

        return "dashboard:stats:u={$uid}:a={$admin}:r={$ids}";
    }
}
