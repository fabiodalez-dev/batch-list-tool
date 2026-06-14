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
 * Renders the barcode/status change timeline on the Box edit/view page
 * (RFQ §3.1.5).
 *
 * Read-only — history is append-only and entries are inserted by the Box
 * model's `updating/updated` observer hook. No create/edit/delete actions
 * are exposed: the only legitimate way to add a row is by changing the
 * parent Box's `barcode` or `barcode_status`.
 */
class BarcodeHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'barcodeHistory';

    protected static ?string $title = 'Barcode history';

    protected static ?string $recordTitleAttribute = 'previous_barcode';

    public function form(Schema $schema): Schema
    {
        // The form schema is required by the RelationManager base class even
        // when no create/edit actions are exposed; we keep it minimal so the
        // ViewAction modal can still render the fields cleanly.
        return $schema->schema([
            Forms\Components\TextInput::make('previous_barcode')
                ->disabled()->maxLength(64),
            Forms\Components\TextInput::make('new_barcode')
                ->disabled()->maxLength(64),
            Forms\Components\TextInput::make('previous_status')->disabled(),
            Forms\Components\TextInput::make('new_status')->disabled(),
            Forms\Components\DateTimePicker::make('changed_at')->disabled(),
            Forms\Components\TextInput::make('reason')->disabled()->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('previous_barcode')
            ->defaultSort('changed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('previous_barcode')
                    ->label('Barcode from')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('new_barcode')
                    ->label('Barcode to')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('previous_status')
                    ->label('Status from')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('new_status')
                    ->label('Status to')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Changed')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('changedBy.name')
                    ->label('By')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('changed_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(fn (Builder $q, array $data): Builder => $q
                        ->when(
                            $data['from'] ?? null,
                            fn ($q, $v) => $q->whereDate('changed_at', '>=', $v),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn ($q, $v) => $q->whereDate('changed_at', '<=', $v),
                        ))
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
