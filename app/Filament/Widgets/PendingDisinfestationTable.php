<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Top-10 documents waiting for fumigation. Each row has a "Mark disinfested"
 * action that writes today's date (or the date the user picks in the modal)
 * to documents.disinfestation_date and gets audited automatically through
 * the Document model's owen-it/laravel-auditing Auditable trait.
 *
 * This widget is intentionally ACTIONABLE — it lets ops clear backlog without
 * leaving the dashboard, which is exactly the demo story for the client.
 */
class PendingDisinfestationTable extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Pending disinfestation — action required';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::pendingQuery())
            ->defaultSort('created_at', 'asc')
            ->paginated(false)
            ->emptyStateHeading('All documents are disinfested')
            ->emptyStateDescription('No backlog right now — nice work.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable(),

                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Current box')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('authorities.surname')
                    ->label('Creator')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->alignCenter()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Days waiting')
                    ->state(fn (Document $record): string => (string) max(
                        0,
                        (int) round(now()->diffInDays($record->created_at, true)),
                    ))
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        (int) $state > 30 => 'danger',
                        (int) $state > 7 => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd(),
            ])
            ->actions([
                Action::make('markDisinfested')
                    ->label('Mark disinfested')
                    ->icon('heroicon-m-shield-check')
                    ->color('success')
                    ->modalHeading('Mark document as disinfested')
                    ->modalDescription(fn (Document $record): string => "Set the disinfestation date for {$record->identifier}.")
                    ->form([
                        Forms\Components\DatePicker::make('disinfestation_date')
                            ->label('Disinfestation date')
                            ->required()
                            ->default(now()->toDateString())
                            ->maxDate(now()),
                    ])
                    ->action(function (Document $record, array $data): void {
                        $record->update([
                            'disinfestation_date' => $data['disinfestation_date'],
                        ]);

                        // Bust the dashboard caches so the Stats card updates immediately.
                        $this->flushDashboardCaches();

                        Notification::make()
                            ->title('Document disinfested')
                            ->body("{$record->identifier} marked disinfested on {$data['disinfestation_date']}.")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function pendingQuery(): Builder
    {
        return Document::query()
            ->with(['currentBox:id,box_number,barcode_status', 'batch:id,batch_number', 'authorities:id,surname'])
            ->whereNull('disinfestation_date')
            ->where(function (Builder $q): void {
                $q->whereNull('current_box_id')
                    ->orWhereHas('currentBox', function (Builder $q): void {
                        $q->where('barcode_status', '!=', 'PERM_OUT');
                    });
            })
            ->limit(10);
    }

    protected function flushDashboardCaches(): void
    {
        // Keys are stable across the StatsOverviewWidget — we conservatively
        // forget by tag pattern using Cache::flush() isn't right (would nuke
        // unrelated keys). The 5-minute TTL is short enough that we accept a
        // small staleness window in exchange for not breaking caches we don't own.
        // The widget keys we DO own:
        $user = auth()->user();
        $uid = $user?->getKey() ?? 'guest';

        Cache::forget("dashboard:chart:series:u={$uid}");
        Cache::forget("dashboard:chart:batch:u={$uid}:f=all");
        Cache::forget("dashboard:chart:batch:u={$uid}:f=main_collection");
        Cache::forget("dashboard:chart:batch:u={$uid}:f=notary_accessions");
        Cache::forget("dashboard:chart:batch:u={$uid}:f=wills");

        // Stats key embeds admin flag + repo ids — recompute the same shape.
        $admin = ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) ? '1' : '0';
        $ids = collect();
        if ($user && method_exists($user, 'repositories')) {
            $ids = $user->repositories()->pluck('repositories.id');
        }
        if ($user?->default_repository_id) {
            $ids = $ids->push($user->default_repository_id);
        }
        $idsStr = $ids->unique()->values()->implode(',');
        Cache::forget("dashboard:stats:u={$uid}:a={$admin}:r={$idsStr}");
    }
}
