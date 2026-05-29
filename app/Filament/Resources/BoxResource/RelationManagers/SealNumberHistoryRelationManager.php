<?php

namespace App\Filament\Resources\BoxResource\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the seal-number change timeline on the Box edit/view page
 * (RFQ Contract App.2-i — the yellow security seal belongs to the BOX, and a
 * history of every seal number is kept for all boxes, especially the Batch 50
 * wills reserve).
 *
 * Read-only — history is append-only and entries are inserted by the Box
 * model's `created` / `updated` hooks. No create/edit/delete actions are
 * exposed: the only legitimate way to add a row is by changing the parent
 * Box's `seal_number`.
 */
class SealNumberHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'sealNumberHistory';

    protected static ?string $title = 'Seal history';

    protected static ?string $recordTitleAttribute = 'new_value';

    public function form(Schema $schema): Schema
    {
        // The form schema is required by the RelationManager base class even
        // when no create/edit actions are exposed; we keep it minimal so the
        // ViewAction modal can still render the fields cleanly.
        return $schema->schema([
            Forms\Components\TextInput::make('old_value')->disabled()->maxLength(255),
            Forms\Components\TextInput::make('new_value')->disabled()->maxLength(255),
            Forms\Components\DateTimePicker::make('changed_at')->disabled(),
            Forms\Components\Textarea::make('notes')->disabled(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('new_value')
            ->defaultSort('changed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('old_value')
                    ->label('Seal from')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('new_value')
                    ->label('Seal to')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Changed')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('changedBy.name')
                    ->label('By')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('changed_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $v) => $q->whereDate('changed_at', '>=', $v),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($q, $v) => $q->whereDate('changed_at', '<=', $v),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['from'])) {
                            $i[] = 'Changed ≥ ' . $data['from'];
                        }
                        if (! empty($data['to'])) {
                            $i[] = 'Changed ≤ ' . $data['to'];
                        }

                        return $i;
                    }),
            ])
            ->headerActions([])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
