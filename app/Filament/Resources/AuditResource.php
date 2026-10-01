<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditResource\Pages;
use App\Models\User;
use App\Support\History\Timeline;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Models\Audit;

/**
 * Read-only Filament view onto the owen-it/laravel-auditing audits table.
 *
 * Lets operators browse "who changed what when" without DB access, satisfying
 * the RFQ §3.1.5 audit trail visibility requirement.
 *
 * No Create/Edit/Delete — audits are write-only via owen-it observers and a
 * tampering surface for the panel would defeat the audit guarantee.
 *
 * Multi-tenant scope (when PR #7 lands): non-admins should see only audits
 * that target Documents/Batches/etc visible under their RepositoryScope.
 * Implemented in getEloquentQuery() below — currently a no-op on main because
 * RepositoryScope is not yet on main.
 */
class AuditResource extends Resource
{
    protected static ?string $model = Audit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getEloquentQuery(): Builder
    {
        // When BelongsToRepository / RepositoryScope land on main (PR #7),
        // restrict non-admin users to audits on auditables they can see.
        // For now: return the unscoped query — Audits are still gated by the
        // Resource's authorization (super_admin / admin only).
        return parent::getEloquentQuery();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Who')
                    ->default('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted', 'restored' => 'danger',
                        'impersonation_started', 'impersonation_ended' => 'gray',
                        default => 'primary',
                    })
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Model')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('Record ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // RFQ §3.1.5 asks for the old value, the new value, the user and
                // the time of every change. The row used to say only that a
                // "Box #4211" was "updated": what changed was one click away
                // per row. Named record, then each changed field as old → new,
                // with ids resolved the way the History tab resolves them.
                Tables\Columns\TextColumn::make('record')
                    ->label('Record')
                    ->state(fn (Audit $record): string => Timeline::recordLabel((string) $record->auditable_type, $record->auditable_id))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (Audit $record): ?string => self::changesSummary($record))
                    ->placeholder('—')
                    ->limit(140)
                    ->tooltip(fn (Audit $record): ?string => self::changesSummary($record))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user_agent')
                    ->limit(40)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        'restored' => 'Restored',
                        'impersonation_started' => 'Impersonation started',
                        'impersonation_ended' => 'Impersonation ended',
                    ]),
                SelectFilter::make('auditable_type')
                    ->label('Model')
                    ->options(fn () => Audit::query()
                        ->select('auditable_type')
                        ->distinct()
                        ->orderBy('auditable_type')
                        ->pluck('auditable_type')
                        ->mapWithKeys(fn (string $type) => [$type => class_basename($type)])
                        ->all()),
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->searchable()
                    ->options(fn (): array => User::query()
                        ->whereIn('id', Audit::query()->select('user_id')->whereNotNull('user_id')->distinct())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Filter::make('field')
                    ->form([
                        TextInput::make('field')
                            ->label('Field changed')
                            ->helperText('The column name, e.g. barcode_status or location_id.'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['field'] ?? null),
                        fn (Builder $query): Builder => $query->where(function (Builder $query) use ($data): void {
                            // The JSON key in quotes. No escaping of _ : a backslash
                            // escape works on MySQL but not on SQLite, and the quotes
                            // already pin the match to one key.
                            $needle = '%"' . trim((string) $data['field']) . '"%';
                            $query->where('old_values', 'like', $needle)->orWhere('new_values', 'like', $needle);
                        }),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['field'] ?? null) ? 'Field: ' . $data['field'] : null),
                Filter::make('record_id')
                    ->form([TextInput::make('id')->label('Record ID')->numeric()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['id'] ?? null),
                        fn (Builder $query): Builder => $query->where('auditable_id', (int) $data['id']),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['id'] ?? null) ? 'Record #' . $data['id'] : null),
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]); // no delete — write-only table
    }

    /**
     * "Batch: 27 → 29; Notes: — → Water damage" for one audit row.
     */
    public static function changesSummary(Audit $audit): ?string
    {
        $parts = array_map(
            fn (array $c): string => $c['label'] . ': ' . ($c['from'] ?? '—') . ' → ' . ($c['to'] ?? '—'),
            Timeline::fieldChanges($audit),
        );

        return $parts === [] ? null : implode('; ', $parts);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAudits::route('/'),
            'view' => Pages\ViewAudit::route('/{record}'),
        ];
    }
}
