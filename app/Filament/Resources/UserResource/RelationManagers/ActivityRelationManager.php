<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the audit activity timeline on the User edit/view page.
 *
 * Shows all events PERFORMED BY this user (actor) — i.e. rows in the `audits`
 * table where `user_id = $user->id`. This is distinct from owen-it's built-in
 * `audits()` which returns changes made TO the user record.
 *
 * Read-only — audit rows are written by owen-it observers and must not be
 * mutated via the panel.
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activityAudits';

    protected static ?string $title = 'Activity';

    /**
     * No form schema needed: this relation manager is read-only and exposes
     * no create / edit actions. The base class requires the method to exist.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted', 'restored' => 'danger',
                        'impersonation_started', 'impersonation_ended' => 'gray',
                        default => 'primary',
                    }),

                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('Record ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])   // no create / attach
            ->actions([])         // no edit / delete / view per-row
            ->bulkActions([])     // no bulk mutations
            ->paginated();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin']);
    }
}
