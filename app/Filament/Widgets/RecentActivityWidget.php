<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\RepositoryResource;
use App\Filament\Resources\SeriesResource;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;

/**
 * Last-10 audit log entries (owen-it/laravel-auditing).
 *
 * Multi-tenant correctness:
 *   - The `audits` table doesn't carry a repository_id.
 *   - For non-admin users we restrict the query to audits whose
 *     `auditable_id` belongs to a Document owned by one of the user's
 *     repositories. This is the most common Auditable in this domain;
 *     other auditables (User, Repository, ...) are admin-only by nature.
 *   - Admin / super_admin see everything.
 */
class RecentActivityWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'Recent Activity';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scopedAuditQuery())
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No recent activity')
            ->emptyStateDescription('Once users start editing records, you will see the audit trail here.')
            ->emptyStateIcon('heroicon-o-clock')
            ->columns([
                Tables\Columns\TextColumn::make('user_name')
                    ->label('Who')
                    ->state(fn (Audit $record): string => $this->resolveUserName($record))
                    ->icon('heroicon-m-user-circle')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('what')
                    ->label('What')
                    ->state(fn (Audit $record): string => $this->describeAudit($record))
                    ->wrap(),

                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->tooltip(fn (Audit $record): string => $record->created_at?->toDateTimeString() ?? '')
                    ->sortable(),
            ])
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Audit $record): ?string => $this->resolveUrl($record))
                    ->visible(fn (Audit $record): bool => $this->resolveUrl($record) !== null),
            ]);
    }

    protected function scopedAuditQuery(): Builder
    {
        $user = auth()->user();
        $isAdmin = $user
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['super_admin', 'admin']);

        $base = Audit::query()->limit(10);

        if ($isAdmin || ! $user) {
            return $base;
        }

        // Build the set of Document ids visible to this user (RepositoryScope
        // is already on Document, so no extra filter needed here).
        $visibleDocIds = Document::query()
            ->limit(2000)            // cap for the dashboard widget
            ->pluck('id')
            ->all();

        return $base->where(function (Builder $q) use ($visibleDocIds): void {
            // Non-admins see only audits attached to a Document they own.
            $q->where(function (Builder $q) use ($visibleDocIds): void {
                $q->where('auditable_type', Document::class)
                    ->whereIn('auditable_id', $visibleDocIds ?: [0]);
            });
        });
    }

    protected function resolveUserName(Audit $audit): string
    {
        if (! $audit->user_id) {
            return 'System';
        }

        /** @var User|null $user */
        $user = User::query()->find($audit->user_id);

        return $user?->name ?? ('User #' . $audit->user_id);
    }

    protected function describeAudit(Audit $audit): string
    {
        $verb = match ($audit->event) {
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            'restored' => 'restored',
            default => $audit->event ?? 'modified',
        };

        $modelLabel = class_basename($audit->auditable_type ?? 'Record');
        $identifier = $this->resolveIdentifier($audit);

        return Str::ucfirst($verb) . ' ' . $modelLabel . ($identifier ? ' ' . $identifier : '');
    }

    protected function resolveIdentifier(Audit $audit): ?string
    {
        $type = $audit->auditable_type;
        $id = $audit->auditable_id;
        if (! $type || ! $id || ! class_exists($type)) {
            return $id ? '#' . $id : null;
        }

        try {
            /** @var Model|null $model */
            $model = $type::query()->find($id);
        } catch (\Throwable) {
            return '#' . $id;
        }

        if (! $model) {
            return '#' . $id;
        }

        foreach (['identifier', 'code', 'name', 'surname', 'box_number', 'batch_number'] as $attr) {
            if (! empty($model->{$attr})) {
                return (string) $model->{$attr};
            }
        }

        return '#' . $id;
    }

    protected function resolveUrl(Audit $audit): ?string
    {
        $type = $audit->auditable_type;
        if (! $type || ! $audit->auditable_id) {
            return null;
        }

        // Map model class → Filament resource URL if available.
        $resourceMap = [
            Document::class => DocumentResource::class,
            Authority::class => AuthorityResource::class,
            Batch::class => BatchResource::class,
            Box::class => BoxResource::class,
            Series::class => SeriesResource::class,
            Accession::class => AccessionResource::class,
            Repository::class => RepositoryResource::class,
        ];

        $resource = $resourceMap[$type] ?? null;
        if (! $resource || ! class_exists($resource)) {
            return null;
        }

        try {
            return $resource::getUrl('view', ['record' => $audit->auditable_id]);
        } catch (\Throwable) {
            try {
                return $resource::getUrl('edit', ['record' => $audit->auditable_id]);
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
