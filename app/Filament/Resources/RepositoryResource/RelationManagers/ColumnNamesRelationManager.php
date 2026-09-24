<?php

namespace App\Filament\Resources\RepositoryResource\RelationManagers;

use App\Models\ColumnLabelOverride;
use App\Support\ColumnLabels\ColumnLabels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Rename a built-in column, for this repository, without a release.
 *
 * Client request 2026-09-24. Her columns had been renamed three times in nine
 * days, each one a code change and a deploy: "It is becoming obvious that we
 * are still renaming/adding new columns. The columns are often not dependent
 * on other parts."
 *
 * Adding columns was already hers to do, one panel across — this is the other
 * half. A rename here changes the header in the downloadable template, the
 * label on the form and record view, the column heading in the table, and the
 * name the importer looks for, all at once.
 *
 * Only the NAME changes. The field, its type and its validation stay in code,
 * so renaming "Warrant Number" cannot change what that column means or which
 * rules it obeys. That is what makes handing it over safe.
 */
class ColumnNamesRelationManager extends RelationManager
{
    protected static string $relationship = 'columnLabelOverrides';

    protected static ?string $title = 'Column names';

    protected static ?string $recordTitleAttribute = 'label';

    /** Same gate as custom fields: renaming a column affects every operator. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('entity_type')
                ->label('Record type')
                ->options(fn (): array => array_combine(
                    ColumnLabels::renameableEntities(),
                    array_map(ucfirst(...), ColumnLabels::renameableEntities()),
                ))
                ->required()
                ->live()
                // Changing the record type invalidates the chosen field.
                ->afterStateUpdated(fn (Forms\Components\Select $component) => $component
                    ->getContainer()
                    ->getComponent('field_key')
                    ?->state(null)),

            Forms\Components\Select::make('field_key')
                ->key('field_key')
                ->label('Column')
                ->options(function (Get $get): array {
                    $entity = (string) $get('entity_type');

                    // Show the factory name, so it is obvious which column is
                    // being renamed even after it has been renamed once.
                    return ColumnLabels::DEFAULTS[$entity] ?? [];
                })
                ->helperText('Only columns that stand on their own can be renamed. Anything the system reads back by name is not listed.')
                ->required()
                ->searchable()
                ->rules([
                    fn (Get $get, ?Model $record): object => Rule::unique('column_label_overrides', 'field_key')
                        ->where('repository_id', $this->getOwnerRecord()->getKey())
                        ->where('entity_type', (string) $get('entity_type'))
                        ->ignore($record?->getKey()),
                ])
                ->validationMessages([
                    'unique' => 'That column already has a new name in this repository — edit the existing row instead of adding a second one.',
                ]),

            Forms\Components\TextInput::make('label')
                ->label('New name')
                ->helperText('What it will be called on the template, the form, the record and the table.')
                ->required()
                ->maxLength(128)
                // Two columns with the same header cannot be told apart on the
                // spreadsheet: the importer keeps one and the other is filled
                // in for nothing. The model refuses it too — this is so she
                // reads why, in the form, instead of meeting an exception.
                ->rule(function (Get $get): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if (ColumnLabels::clashesWith(
                            (string) $get('entity_type'),
                            (string) $get('field_key'),
                            (string) $value,
                            (int) $this->getOwnerRecord()->getKey(),
                        )) {
                            $fail(sprintf(
                                'Another column in this repository is already called "%s". Pick a different name.',
                                trim((string) $value),
                            ));
                        }
                    };
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->emptyStateHeading('No columns renamed')
            ->emptyStateDescription('Built-in columns keep the names they ship with until you rename one here.')
            ->columns([
                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Record type')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('field_key')
                    ->label('Ships as')
                    // The factory name, not the key: "NAM Authority Reference
                    // Code" tells the cataloguer which column this is;
                    // "identifier" does not.
                    ->formatStateUsing(fn (string $state, ColumnLabelOverride $record): string => ColumnLabels::DEFAULTS[$record->entity_type][$state] ?? $state)
                    ->description(fn (ColumnLabelOverride $record): string => $record->field_key)
                    ->searchable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Now called')
                    ->weight('medium')
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Renamed')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->label('Record type')
                    ->options(fn (): array => array_combine(
                        ColumnLabels::renameableEntities(),
                        array_map(ucfirst(...), ColumnLabels::renameableEntities()),
                    )),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Rename a column')
                    // Without these the dialog is titled "Create Column Label
                    // Override" and its button says "Create" — the name of the
                    // table, offered to an archivist who is renaming a column.
                    ->modalHeading('Rename a column')
                    ->modalSubmitActionLabel('Rename')
                    ->createAnotherAction(fn (Action $action) => $action->label('Rename & rename another'))
                    ->after(fn () => ColumnLabels::flushMemo()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Change the name')
                    ->modalHeading('Change the name')
                    ->modalSubmitActionLabel('Save the new name')
                    ->after(fn () => ColumnLabels::flushMemo()),
                DeleteAction::make()
                    ->label('Restore original name')
                    ->modalHeading('Restore the original name?')
                    ->modalDescription('The column goes back to the name it ships with, on the template and everywhere else.')
                    // The inherited button says "Delete", under a heading that
                    // offers to restore a name. Nothing is deleted that anyone
                    // catalogued, and a red "Delete" invites the reader to
                    // wonder what else is going with it.
                    ->modalSubmitActionLabel('Restore the original name')
                    ->after(fn () => ColumnLabels::flushMemo()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->after(fn () => ColumnLabels::flushMemo()),
                ]),
            ])
            ->defaultSort('entity_type');
    }
}
