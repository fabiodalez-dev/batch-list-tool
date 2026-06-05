<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BatchResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Lookup\BatchType;
use App\Models\Repository;
use App\Support\CustomFields\CustomFieldSchema;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BatchResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'batch';

    protected static ?string $model = Batch::class;

    // A3 — "New batch" → "New Batch": set the model label explicitly to
    // title-case so Filament's CreateAction renders "New Batch".
    protected static ?string $modelLabel = 'Batch';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'batch_number';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1), atomic Sections use
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\TextInput::make('batch_number')
                            ->label('Batch Number')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            // A2 — suggest the next sequential batch number as the
                            // default, skipping forbidden numbers 34 and 36.
                            // On edit the record's own value is used (no default).
                            ->default(static function (): int {
                                $max = Batch::max('batch_number') ?? 0;
                                $next = $max + 1;
                                // Skip over every forbidden number.
                                while (in_array($next, Batch::FORBIDDEN_NUMBERS, true)) {
                                    $next++;
                                }

                                return $next;
                            })
                            // A2 — catch duplicate batch_number within the same
                            // repository and show a friendly validation message
                            // instead of letting a SQL unique violation bubble to a
                            // 500 error page.
                            //
                            // We use a closure validator (not Rule::unique) because
                            // Rule::unique does not expose a per-instance message API
                            // in this Laravel version.  The closure calls $fail() with
                            // exactly the text the spec requires.
                            ->rule(function (?Batch $record, Get $get): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($record, $get): void {
                                    if ($value === null || $value === '') {
                                        return;
                                    }

                                    $repositoryId = $get('repository_id')
                                        ?? $record?->repository_id
                                        ?? auth()->user()?->default_repository_id;

                                    $query = Batch::query()
                                        ->where('batch_number', (int) $value)
                                        ->where('repository_id', $repositoryId);

                                    if ($record !== null && $record->exists) {
                                        $query->whereKeyNot($record->getKey());
                                    }

                                    if ($query->exists()) {
                                        $fail('Batch number already exists.');
                                    }
                                };
                            })
                            // RFQ rule #1: batches 34 and 36 are forbidden (unused,
                            // never to be used); batch 33 is reserved for old MAV
                            // boxes and remains a valid number.
                            // The Batch model defines FORBIDDEN_NUMBERS — we use the
                            // model helper so the rule has a single source of truth
                            // (model const + form validator + DB CHECK on MySQL).
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    if ($value === null || $value === '') {
                                        return;
                                    }
                                    $candidate = new Batch(['batch_number' => (int) $value]);
                                    if ($candidate->isForbidden()) {
                                        $fail("Batch number {$value} is reserved/forbidden (RFQ rule).");
                                    }
                                };
                            })),
                        // RFQ §3.1.11 — expose the batch_types lookup as form
                        // options. batches.type retains its DB ENUM
                        // (MAIN_COLLECTION / NOTARY_ACCESSION) and is NOT given a
                        // strict model-level lookup guard, so existing data /
                        // tests are unaffected; the Select simply surfaces the
                        // editable controlled vocabulary in the UI.
                        $g(Forms\Components\Select::make('type')
                            // C4 — include the record's CURRENT value even if it
                            // has since been deactivated, so editing other fields
                            // never drops/blanks a stored-but-inactive type.
                            ->options(fn (?Batch $record): array => BatchType::optionsWith($record?->type))
                            ->required()),
                        $g(Forms\Components\TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpanFull()),
                    ]),

                Section::make('Scope & status')
                    ->columns($twoCols)
                    ->schema([
                        // NOTE: tenant-scoping `disabled()` closure stays on the
                        // Select; the field-level gate adds a second layer. Both
                        // must allow for the input to be writable.
                        //
                        // Server-side search (no preload) — see SearchableSelects.
                        $g(SearchableSelects::repository(
                            'repository_id',
                            fn ($query) => $query->whereIn(
                                'id',
                                auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                    ? Repository::query()->pluck('id')->all()
                                    : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                            ),
                        )
                            ->label('Repository')
                            ->required()
                            // live() so the Custom fields Section re-renders when
                            // the operator picks a different repository (GROUP A fix).
                            ->live()
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        // A10 (spec) — is_active is non-mandatory (default true);
                        // removing ->required() so the asterisk is gone.
                        $g(Forms\Components\Toggle::make('is_active')
                            ->default(true)),
                    ]),

                // Custom fields (EAV, per-repository).
                // Definitions are created by super_admin via the Repository admin panel.
                //
                // Repository resolution order (GROUP A fix):
                //   1. Live form state: $get('repository_id') — reflects the operator's
                //      current selection in real-time (repository_id is ->live()).
                //   2. Fallback to record repository_id (on edit, before any change).
                //   3. Fallback to the user's default repository (on create, nothing selected yet).
                Section::make('Custom fields')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(static function (Get $get, ?Batch $record): array {
                        $repositoryId = (int) $get('repository_id')
                            ?: $record?->repository_id
                            ?: auth()->user()?->default_repository_id;

                        return CustomFieldSchema::for('batch', $repositoryId !== null ? (int) $repositoryId : null);
                    })
                    ->visible(static function (Get $get, ?Batch $record): bool {
                        $repositoryId = (int) $get('repository_id')
                            ?: $record?->repository_id
                            ?: auth()->user()?->default_repository_id;

                        return count(CustomFieldSchema::for('batch', $repositoryId !== null ? (int) $repositoryId : null)) > 0;
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1), atomic Sections on
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        // Every FK gets a clickable URL to its Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('batch_number')
                            ->label('Batch number')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('type')
                            ->label('Type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Scope & status')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('repository.code')
                            ->label('Repository')
                            ->badge()
                            ->color('info')
                            ->url(fn (?Batch $record): ?string => $record?->repository_id
                                ? route('filament.admin.resources.repositories.view', ['record' => $record->repository_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('boxes_count')
                            ->label('Boxes')
                            ->state(fn (?Batch $record): int => $record?->boxes()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Batch $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                    ]),

                // Custom fields (EAV, per-repository) — view/infolist section.
                // Shows label → formatted value for every active definition that
                // has a stored value on this record.
                Section::make('Custom fields')
                    ->columns($twoCols)
                    ->schema(static function (?Batch $record): array {
                        if ($record === null) {
                            return [];
                        }
                        $data = $record->getCustomFieldData();
                        if (empty($data)) {
                            return [];
                        }
                        $entries = [];
                        foreach ($record->customFieldDefinitions()->get() as $def) {
                            $value = $data[$def->key] ?? null;
                            if ($value === null) {
                                continue;
                            }
                            $displayValue = match ($def->type) {
                                'boolean' => $value ? 'Yes' : 'No',
                                'date' => $value instanceof Carbon ? $value->toDateString() : (string) $value,
                                'datetime' => $value instanceof Carbon ? $value->toDateTimeString() : (string) $value,
                                default => (string) $value,
                            };
                            $entries[] = TextEntry::make('cf_' . $def->key)
                                ->label($def->label)
                                ->state($displayValue)
                                ->placeholder('—');
                        }

                        return $entries;
                    })
                    ->visible(static function (?Batch $record): bool {
                        if ($record === null) {
                            return false;
                        }
                        $data = $record->getCustomFieldData();

                        return ! empty(array_filter($data, fn ($v) => $v !== null));
                    }),

                Section::make('Audit info')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('updated_at')->dateTime()->label('Updated'),
                        TextEntry::make('deleted_at')->dateTime()->label('Trashed')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
            ]);
    }

    public static function table(Table $table): Table
    {
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            // Feedback1 Wave B (B1) — applied filters must not reset on
            // refresh/navigation: persist them in the query string and defer
            // their application until the operator hits "Apply".
            ->deferFilters()
            ->persistFiltersInSession()
            // A8 — only the Batch Number cell is the hyperlink to the batch
            // view; the whole-row recordUrl is removed so other cells are plain
            // text.  View / Edit remain reachable via the row-actions column.
            ->columns([
                $gc(Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch Number')
                    ->numeric()
                    ->sortable()
                    // A8 — hyperlink only this cell, not the whole row.
                    ->url(fn (?Batch $record): ?string => $record !== null
                        ? static::getUrl('view', ['record' => $record])
                        : null)
                    ->color('primary')),
                $gc(Tables\Columns\TextColumn::make('description')
                    ->searchable()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('type')
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('repository.name')
                    ->label('Repository')
                    ->sortable(), 'repository_id'),
                $gc(Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable()),
                // A9 — inputter column (who created the record).
                CreatorColumn::make(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Custom fields (EAV) — toggleable columns for active definitions.
                ...DocumentResource::customFieldTableColumns('batch'),
            ])
            ->filters([
                // Feedback1 Wave B (B1) — dropdown-driven filters alongside the
                // free-text search on `description`. BatchResource is light
                // enough that plain SelectFilters cover it (no QueryBuilder).
                SelectFilter::make('batch_number')
                    ->label('Batch number')
                    ->options(fn (): array => Batch::query()
                        ->orderBy('batch_number')
                        ->pluck('batch_number', 'batch_number')
                        ->all())
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('type')
                    ->options(fn (): array => BatchType::optionsWith(null))
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                // Feedback1 Wave B (B3) — explicit "View boxes" row action as a
                // discoverable alternative to the whole-row recordUrl above.
                Action::make('viewBoxes')
                    ->label('View boxes')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->url(fn (Batch $record): string => BoxResource::getUrl('index', [
                        'filters' => ['batch' => ['values' => [$record->getKey()]]],
                    ])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBatches::route('/'),
            'create' => Pages\CreateBatch::route('/create'),
            'view' => Pages\ViewBatch::route('/{record}'),
            'edit' => Pages\EditBatch::route('/{record}/edit'),
        ];
    }

    /**
     * Eager-load customFieldValues.definition and the first audit entry (for
     * the CreatorColumn / Inputter column) to avoid N+1 in the table.
     *
     * The audits sub-load is filtered to event='created' and ordered by id asc
     * so only the relevant row is fetched per batch record.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'customFieldValues.definition',
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
        ]);
    }
}
