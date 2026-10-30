<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditResource\Pages;
use App\Filament\Support\EqualsFirst;
use App\Filament\Support\ListRules;
use App\Filament\Support\MatchQueryBuilder;
use App\Models\User;
use App\Support\DisplayDate;
use App\Support\History\Timeline;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint\Operators as TextOps;
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
                    ->dateTime(DisplayDate::DATE_TIME_SECONDS)
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
                // Client 2026-10-30: one rule per column, as on Documents.
                MatchQueryBuilder::make()
                    ->constraints([
                        ListRules::date('created_at', 'When'),
                        // `user` is a morph-to on the audit: a plain select on
                        // user_id.
                        SelectConstraint::make('user_id')
                            ->label('Who')
                            ->searchable()
                            ->multiple()
                            ->nullable()
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                        SelectConstraint::make('event')
                            ->options([
                                'created' => 'Created',
                                'updated' => 'Updated',
                                'deleted' => 'Deleted',
                                'restored' => 'Restored',
                                'impersonation_started' => 'Impersonation started',
                                'impersonation_ended' => 'Impersonation ended',
                            ])
                            ->multiple(),
                        SelectConstraint::make('auditable_type')
                            ->label('Model')
                            ->options(fn () => Audit::query()
                                ->select('auditable_type')
                                ->distinct()
                                ->orderBy('auditable_type')
                                ->pluck('auditable_type')
                                ->mapWithKeys(fn (string $type) => [$type => class_basename($type)])
                                ->all())
                            ->multiple(),
                        EqualsFirst::number('auditable_id', 'Record ID'),
                        // The JSON key in quotes. No escaping of _ : a backslash
                        // escape works on MySQL but not on SQLite, and the quotes
                        // already pin the match to one key.
                        TextConstraint::make('changed_field')
                            ->label('Field changed')
                            ->icon('heroicon-o-pencil-square')
                            ->operators([
                                TextOps\EqualsOperator::make()->modifyBaseQueryUsing(function (Builder $query, ?array $settings, ?bool $isInverse): Builder {
                                    $field = trim((string) (is_scalar($settings['text'] ?? null) ? $settings['text'] : ''));
                                    if ($field === '') {
                                        return $query;
                                    }
                                    $needle = '%"' . $field . '"%';
                                    $changed = fn (Builder $q): Builder => $q->where('old_values', 'like', $needle)->orWhere('new_values', 'like', $needle);

                                    return $isInverse ? $query->whereNot($changed) : $query->where($changed);
                                }),
                            ]),
                        EqualsFirst::text('ip_address', 'Ip address', nullable: true),
                        EqualsFirst::text('user_agent', 'User agent', nullable: true),
                    ]),
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
