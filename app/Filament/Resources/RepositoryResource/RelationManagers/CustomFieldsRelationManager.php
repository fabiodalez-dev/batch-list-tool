<?php

namespace App\Filament\Resources\RepositoryResource\RelationManagers;

use App\Models\CustomFieldDefinition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Manage per-repository custom field definitions (spec §Admin CRUD).
 *
 * Restricted to super_admin: only super_admin can view, create, edit or delete
 * field definitions. Normal admins and editors interact only with field
 * *values* (entered through host-resource forms), not with definitions here.
 *
 * Field-permission-matrix integration: OUT OF SCOPE for v1 (spec §Permissions).
 */
class CustomFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'customFieldDefinitions';

    protected static ?string $title = 'Custom fields';

    protected static ?string $recordTitleAttribute = 'label';

    // ── Gate ─────────────────────────────────────────────────────────────────

    /**
     * Only super_admin may see or use this panel at all.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    // ── Form ─────────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        // Build human-readable option maps from the model constants.
        $entityTypeOptions = array_map(
            fn (string $class): string => class_basename($class),
            CustomFieldDefinition::ENTITY_TYPES,
        );

        $typeOptions = array_combine(
            CustomFieldDefinition::TYPES,
            array_map(
                fn (string $t): string => ucfirst($t),
                CustomFieldDefinition::TYPES,
            ),
        );

        return $schema->schema([
            // entity_type — scope to a specific Filament entity.
            // GROUP E fix: immutable once the definition has associated values
            // (changing it would silently re-scope or orphan existing stored data).
            Forms\Components\Select::make('entity_type')
                ->label('Entity type')
                ->options($entityTypeOptions)
                ->required()
                ->native(false)
                ->helperText('Which resource this field will appear on.')
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record !== null && $record->values()->exists())
                ->helperText(fn (?CustomFieldDefinition $record): string => ($record !== null && $record->values()->exists())
                    ? 'Locked — this field has stored values. Changing the entity type would orphan existing data.'
                    : 'Which resource this field will appear on.'),

            // label — human-readable name shown in forms/views.
            Forms\Components\TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(128)
                ->live(debounce: 400)
                ->afterStateUpdated(function (string $operation, $state, Set $set): void {
                    // Auto-suggest the key from the label on create only;
                    // on edit the key is immutable (disabledOn).
                    if ($operation !== 'create') {
                        return;
                    }

                    $set('key', Str::snake(Str::ascii((string) $state)));
                }),

            // key — machine key, must be unique per (repository_id, entity_type, key).
            Forms\Components\TextInput::make('key')
                ->label('Key')
                ->required()
                ->maxLength(64)
                ->regex('/^[a-z][a-z0-9_]*$/')
                ->helperText('Snake_case identifier, e.g. "custom_date". Immutable after creation.')
                ->disabledOn('edit')
                ->dehydratedWhenHidden()
                // Unique-scoped rule: wrap in an outer factory closure so Filament 5's
                // EvaluatesClosures does not attempt to inject $attribute (which it cannot
                // resolve), following the same pattern used in BatchResource::form().
                // entity_type is read from the Livewire component's mounted actions data array.
                ->rule(function (): \Closure {
                    return function (string $attribute, mixed $value, \Closure $fail): void {
                        $owner = $this->getOwnerRecord();

                        // Grab entity_type from the last mounted action's raw data payload
                        // ($this->mountedActions is the Filament 5 Livewire property holding
                        // the action stack; the innermost action is the last element).
                        $lastAction = ! empty($this->mountedActions) ? end($this->mountedActions) : [];
                        $entityType = $lastAction['data']['entity_type'] ?? null;

                        $currentRecordKey = $this->getMountedAction()?->getRecord()?->getKey();

                        $exists = CustomFieldDefinition::query()
                            ->where('repository_id', $owner->getKey())
                            ->when($entityType !== null, fn ($q) => $q->where('entity_type', $entityType))
                            ->where('key', $value)
                            ->when(
                                $currentRecordKey !== null,
                                fn ($q) => $q->whereKeyNot($currentRecordKey),
                            )
                            ->exists();

                        if ($exists) {
                            $fail("The key '{$value}' is already taken for this entity type in this repository.");
                        }
                    };
                }),

            // type — determines which Filament component is rendered.
            // GROUP E fix: immutable once the definition has associated values
            // (changing type would silently reinterpret existing stored data —
            // e.g. turning a boolean '1' into a number or a date string).
            Forms\Components\Select::make('type')
                ->label('Type')
                ->options($typeOptions)
                ->required()
                ->native(false)
                ->live()
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record !== null && $record->values()->exists())
                ->helperText(fn (?CustomFieldDefinition $record): string => ($record !== null && $record->values()->exists())
                    ? 'Locked — this field has stored values. Changing the type would silently reinterpret existing data.'
                    : 'Determines input widget and value casting.'),

            // options — repeater for select fields only.
            // GROUP E fix: also disabled when the definition has stored values
            // (adding/removing/changing options would misrepresent existing data).
            Forms\Components\Repeater::make('options')
                ->label('Options')
                ->helperText('Add one row per selectable option.')
                ->columnSpanFull()
                ->defaultItems(1)
                ->addActionLabel('Add option')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('value')
                        ->label('Value (stored)')
                        ->required()
                        ->maxLength(128),

                    Forms\Components\TextInput::make('label')
                        ->label('Label (displayed)')
                        ->required()
                        ->maxLength(128),
                ])
                ->visible(fn (Get $get): bool => $get('type') === 'select')
                ->required(fn (Get $get): bool => $get('type') === 'select')
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record !== null && $record->values()->exists()),

            // Toggles.
            Forms\Components\Toggle::make('is_required')
                ->label('Required')
                ->default(false)
                ->helperText('Hosts must fill this field before saving.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Inactive fields are hidden from create/edit forms.'),

            // help_text — shown below the rendered input on host forms.
            Forms\Components\TextInput::make('help_text')
                ->label('Help text')
                ->maxLength(255)
                ->helperText('Hint shown below the field in create/edit forms. Optional.'),

            // sort_order — governs display sequence within a section.
            Forms\Components\TextInput::make('sort_order')
                ->label('Sort order')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->helperText('Lower numbers appear first.'),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') === true;

        $entityTypeOptions = array_map(
            fn (string $class): string => class_basename($class),
            CustomFieldDefinition::ENTITY_TYPES,
        );

        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Entity')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->fontFamily('mono')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->label('Entity type')
                    ->options($entityTypeOptions),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible($isSuperAdmin)
                    ->mutateFormDataUsing(function (array $data): array {
                        // Inject repository_id from the owner Repository.
                        $data['repository_id'] = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->visible($isSuperAdmin),

                DeleteAction::make()
                    ->visible($isSuperAdmin),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible($isSuperAdmin),
                ]),
            ]);
    }
}
