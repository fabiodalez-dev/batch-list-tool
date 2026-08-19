<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Models\BoxMovement;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the per-document box-movement TIMELINE on the Document edit/view page
 * (client 2026-08-18 #1).
 *
 * Rows come from two sources:
 *   - 'recorded' moves, created by MoveToBoxAction with a real date;
 *   - 'legacy_import' moves, reconstructed by DocumentImporter from the box
 *     provenance columns. The client cannot date those, so they carry a NULL
 *     `movement_date`, are flagged, and sort FIRST (see BoxMovement::ordered).
 *
 * No create/delete actions are exposed — moves are only ever created by the
 * importer or by MoveToBoxAction. A limited EditAction lets an operator backfill
 * a real date onto a legacy move (and correct its reason); typing a date flips
 * the row to 'recorded' automatically via the BoxMovement saving() invariant.
 */
class BoxMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movement history';

    public function form(Schema $schema): Schema
    {
        // Only the two operator-editable fields. Everything else (the boxes, the
        // sequence, the date_source flag) is derived by the importer / model and
        // must not be hand-edited.
        return $schema->schema([
            Forms\Components\DateTimePicker::make('movement_date')
                ->label('Movement date')
                ->helperText('Leave blank for an undated legacy move. Setting a real date converts it to a recorded move.'),
            Forms\Components\Textarea::make('reason')
                ->label('Reason')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                /** @var Builder<BoxMovement> $query */
                return $query->reorder()->ordered();
            })
            ->columns([
                Tables\Columns\TextColumn::make('to_box')
                    ->label('To box')
                    ->state(fn (BoxMovement $record): ?string => self::boxLabel($record->toBox))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('from_box')
                    ->label('From box')
                    ->state(fn (BoxMovement $record): ?string => self::boxLabel($record->fromBox))
                    ->placeholder('Entered custody'),

                Tables\Columns\TextColumn::make('movement_date')
                    ->label('Date')
                    ->dateTime()
                    ->placeholder('Not recorded (legacy import)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('date_source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === BoxMovement::DATE_SOURCE_LEGACY
                        ? 'Legacy import'
                        : 'Recorded')
                    ->color(fn (string $state): string => $state === BoxMovement::DATE_SOURCE_LEGACY
                        ? 'warning'
                        : 'success'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Recorded by')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->headerActions([])
            ->actions([
                EditAction::make()
                    ->form([
                        Forms\Components\DateTimePicker::make('movement_date')
                            ->label('Movement date')
                            ->helperText('Leave blank for an undated legacy move. Setting a real date converts it to a recorded move.'),
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason')
                            ->rows(2),
                    ]),
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

        return method_exists($user, 'can')
            ? (bool) $user->can('view_document')
            : false;
    }

    /**
     * "<box_number> (<box_type>)" for a box, or just the number when the type is
     * blank; null when there is no box (a null from_box = entered custody).
     */
    private static function boxLabel(?Model $box): ?string
    {
        if ($box === null) {
            return null;
        }

        $number = $box->getAttribute('box_number');
        $type = $box->getAttribute('box_type');

        if ($number === null || $number === '') {
            return $type !== null && $type !== '' ? (string) $type : null;
        }

        return $type !== null && $type !== ''
            ? sprintf('%s (%s)', $number, $type)
            : (string) $number;
    }
}
