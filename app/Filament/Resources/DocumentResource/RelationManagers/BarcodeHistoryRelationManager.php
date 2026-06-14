<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

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
 * Renders the per-document barcode value change timeline on the Document
 * edit/view page (Task 7b — RFQ Wave 2 expansion).
 *
 * Read-only — history is append-only and entries are inserted by the Document
 * model's `created` / `updated` hooks. No create/edit/delete actions are
 * exposed: the only legitimate way to add a row is by changing the parent
 * Document's `barcode` value.
 *
 * NOTE: this table shows the document's OWN barcode value history. The
 * document's custody STATUS (barcode_status) is still authoritative from the
 * box (Task 7 mirror) and is NOT tracked here.
 */
class BarcodeHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'barcodeHistory';

    protected static ?string $title = 'Barcode history';

    protected static ?string $recordTitleAttribute = 'new_value';

    public function form(Schema $schema): Schema
    {
        // The form schema is required by the RelationManager base class even
        // when no create/edit actions are exposed; keep it minimal so the
        // ViewAction modal can still render fields cleanly.
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
                    ->label('Barcode from')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('new_value')
                    ->label('Barcode to')
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
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        // Admins see all history; editors / viewers need the Shield perm.
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can') && (bool) $user->can('view_document');
    }
}
