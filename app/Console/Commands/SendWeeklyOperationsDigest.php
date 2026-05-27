<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\WeeklyOperationsDigest;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Compose + send the weekly operations digest to every super_admin / admin.
 *
 * Idempotent: re-running on the same day sends the digest again with the
 * same numbers (no de-dup guard) — the schedule entry runs once weekly so
 * the manual re-invocation is the operator's call.
 */
class SendWeeklyOperationsDigest extends Command
{
    protected $signature = 'nra:send-weekly-digest {--dry-run : Print the stats without sending email}';

    protected $description = 'Email the weekly operations digest to super_admin + admin users.';

    public function handle(): int
    {
        $stats = $this->computeStats();

        if ($this->option('dry-run')) {
            $this->info('Dry run — stats computed but no email sent:');
            $this->table(['Metric', 'Value'], collect($stats)->map(
                fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : (string) $v],
            )->values()->all());

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        if (empty($recipients)) {
            $this->warn('No super_admin/admin recipients found — digest skipped.');

            return self::SUCCESS;
        }

        Mail::to($recipients)->send(new WeeklyOperationsDigest($stats));
        $this->info(sprintf('Digest sent to %d recipient(s).', count($recipients)));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeStats(): array
    {
        $weekStart = now()->subWeek();

        return [
            'period_start' => $weekStart->format('Y-m-d'),
            'period_end' => now()->format('Y-m-d'),
            'documents_added_this_week' => Document::query()->where('created_at', '>=', $weekStart)->count(),
            'documents_total' => Document::query()->count(),
            'pending_disinfestation_over_30d' => Document::query()
                ->whereNull('disinfestation_date')
                ->where('created_at', '<=', now()->subDays(30))
                ->count(),
            'flags_open_by_severity' => DocumentFlag::query()
                ->whereIn('status', ['open', 'acknowledged'])
                ->selectRaw('severity, COUNT(*) as cnt')
                ->groupBy('severity')
                ->pluck('cnt', 'severity')
                ->all(),
        ];
    }
}
