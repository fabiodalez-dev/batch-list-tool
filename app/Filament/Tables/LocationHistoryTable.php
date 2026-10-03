<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The location trail of a box or a document (RFQ §3.1.6): where it was, where
 * it went, when and by whom. Shared by the two "Location history" tabs so they
 * read alike. Read-only — rows are written by the model hooks.
 *
 * The labels are the breadcrumbs captured at the time of the move, so the
 * trail stays readable after a location is renamed or deleted.
 */
final class LocationHistoryTable
{
    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('from_location_label')->label('From')->disabled(),
            Forms\Components\TextInput::make('to_location_label')->label('To')->disabled(),
            Forms\Components\DateTimePicker::make('changed_at')->label('Moved')->disabled(),
            Forms\Components\Textarea::make('notes')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_location_label')
            ->defaultSort('changed_at', 'desc')
            ->emptyStateHeading('No location changes recorded yet')
            ->emptyStateDescription('Every change of location is recorded here from the moment it happens.')
            ->columns([
                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Moved')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('from_location_label')
                    ->label('From')
                    ->placeholder('— (no location)')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('to_location_label')
                    ->label('To')
                    ->placeholder('— (no location)')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('changedBy.name')
                    ->label('By')
                    ->placeholder('system'),

                Tables\Columns\TextColumn::make('source')
                    ->label('How')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'create' => 'Set on creation',
                        'update' => 'Moved',
                        'documents' => 'From its documents',
                        default => (string) $state,
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('notes')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('changed_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $v): Builder => $query->whereDate('changed_at', '>=', $v))
                        ->when($data['to'] ?? null, fn (Builder $query, $v): Builder => $query->whereDate('changed_at', '<=', $v)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (! empty($data['from'])) {
                            $indicators[] = 'Moved ≥ ' . $data['from'];
                        }
                        if (! empty($data['to'])) {
                            $indicators[] = 'Moved ≤ ' . $data['to'];
                        }

                        return $indicators;
                    }),
            ])
            ->headerActions([])
            ->actions([ViewAction::make()])
            ->bulkActions([]);
    }
}
