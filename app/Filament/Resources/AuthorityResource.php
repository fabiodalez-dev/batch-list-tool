<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\AuthorityResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Authority;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AuthorityResource extends Resource
{
    use AppliesFieldPermissions;

    /**
     * Feedback1 G-? — entity_type is now a constrained vocabulary
     * (Notary / Interventor) instead of free text. Kept as a constant so
     * the option set stays extensible without touching the form. Any value
     * already stored on a record (e.g. legacy 'PERSON') is merged into the
     * options at render time so existing rows remain editable/saveable.
     *
     * @var array<string, string>
     */
    public const ENTITY_TYPES = [
        'Notary' => 'Notary',
        'Interventor' => 'Interventor',
    ];

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const string FIELD_PERMISSIONS_KEY = 'authority';

    protected static ?string $model = Authority::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'surname';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) so every Section is a
        // full-width band; atomic-field Sections use ['default' => 1, 'md' => 2];
        // non-atomic children (Textarea) take columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — identifier is required, unique and must
                        // start with R or I (Register / Interventor families).
                        $g(Forms\Components\TextInput::make('identifier')
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^[RI]/i')
                            ->validationMessages([
                                'regex' => 'Identifier must start with R or I.',
                            ])),
                        // alternative_identifier is optional but, when filled,
                        // must start with MS and be unique across creators.
                        $g(Forms\Components\TextInput::make('alternative_identifier')
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->helperText('Starts with MS (optional)')
                            ->rules([
                                'nullable',
                                'regex:/^MS/i',
                            ])
                            ->validationMessages([
                                'regex' => 'Alternative identifier must start with MS.',
                            ])),
                        $g(Forms\Components\TextInput::make('surname')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 — "New Creator Given Name should be mandatory".
                        $g(Forms\Components\TextInput::make('given_names')
                            ->label('Given name')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 — replace free-text "Person" with a
                        // constrained Notary / Interventor vocabulary. Existing
                        // values (e.g. legacy 'PERSON') are merged into the
                        // options so the record stays editable/saveable.
                        $g(Forms\Components\Select::make('entity_type')
                            ->required()
                            ->native(false)
                            ->options(fn (?Authority $record): array => self::entityTypeOptions($record?->entity_type))
                            ->default('Notary')),
                    ]),

                Section::make('Practice dates')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — optional, but each accepts only a 4-digit
                        // year and end must be ≥ start when both are filled.
                        $g(Forms\Components\TextInput::make('practice_dates_start')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])),
                        $g(Forms\Components\TextInput::make('practice_dates_end')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])
                            // Closure rule so the comparison is skipped when
                            // either bound is empty (both dates are optional).
                            ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $start = $get('practice_dates_start');
                                if ($value === null || $value === '' || $start === null || $start === '') {
                                    return;
                                }
                                if ((int) $value < (int) $start) {
                                    $fail('End year must be greater than or equal to the start year.');
                                }
                            })),
                        // Feedback1 C1.2 — optional NTG (Notary to Government)
                        // date. Presence of a value is what the "worked as NTG"
                        // filter keys off.
                        $g(Forms\Components\DatePicker::make('ntg_date')
                            ->label('NTG date')
                            ->helperText('Date the creator worked as Notary to Government (if applicable)')
                            ->native(false)),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        $g(Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull()),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1), atomic Sections use
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('identifier')
                            ->label('Identifier')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('alternative_identifier')
                            ->label('Alternative identifier')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('surname')
                            ->label('Surname')
                            ->placeholder('—'),
                        TextEntry::make('given_names')
                            ->label('Given names')
                            ->placeholder('—'),
                        TextEntry::make('entity_type')
                            ->label('Entity type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                    ]),

                Section::make('Practice dates')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('practice_dates_start')
                            ->label('From')
                            ->placeholder('—'),
                        TextEntry::make('practice_dates_end')
                            ->label('To')
                            ->placeholder('—'),
                        TextEntry::make('practice_dates_display')
                            ->label('Range')
                            ->state(function (?Authority $record): string {
                                $start = $record?->practice_dates_start;
                                $end = $record?->practice_dates_end;
                                if ($start && $end) {
                                    return "{$start} – {$end}";
                                }
                                if ($start) {
                                    return "from {$start}";
                                }
                                if ($end) {
                                    return "to {$end}";
                                }

                                return '—';
                            })
                            ->columnSpanFull(),
                        // Feedback1 C1.2 — surface the NTG date on the View page.
                        TextEntry::make('ntg_date')
                            ->label('NTG date')
                            ->date()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No notes.')
                            ->columnSpanFull(),
                    ]),

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
            // Feedback1 Wave B (B1) — persist & defer filters so an applied
            // filter set is not lost on navigation/refresh (client complaint:
            // "when filters are applied they seem to reset").
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 — creators sorted by Identifier by default.
            ->defaultSort('identifier')
            // Feedback1 Wave A (A6) — drag-and-drop column reordering, mirroring
            // DocumentResource and BoxResource (spec: all main resource lists).
            ->reorderableColumns()
            // Feedback1 — expose first/last page links in the paginator so
            // users can jump to the ends of large creator lists.
            ->extremePaginationLinks()
            ->columns([
                $gc(Tables\Columns\TextColumn::make('identifier')
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('alternative_identifier')
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('surname')
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('given_names')
                    ->label('Given name')
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('entity_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::ENTITY_TYPES[$state] ?? (string) $state)
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_start')
                    ->numeric()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_end')
                    ->numeric()
                    ->sortable()),
                // Feedback1 C1.2 — NTG date column, toggleable (off by default
                // to keep the default grid focused on identity columns).
                $gc(Tables\Columns\TextColumn::make('ntg_date')
                    ->label('NTG date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
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
                // A9 — inputter column (who created the record).
                CreatorColumn::make(),
            ])
            ->filters([
                // Feedback1 Wave B (B1) — rich filter mechanism (#1) on top of
                // the free-text column search (#2). The QueryBuilder natively
                // supports AND/OR/NOT nested groups with per-field dropdown
                // constraints — exactly the "select a field then a dropdown"
                // UX the client asked for. NOTE (client comment): identifier is
                // available as a constraint here but is NOT also added as a
                // standalone free-text filter — there is already a dedicated
                // identifier column, so duplicating it would be redundant.
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('identifier')
                            ->label('Identifier'),
                        TextConstraint::make('alternative_identifier')
                            ->label('MS / alternative identifier'),
                        TextConstraint::make('surname')
                            ->label('Surname'),
                        TextConstraint::make('given_names')
                            ->label('Given name'),
                        SelectConstraint::make('entity_type')
                            ->label('Entity type')
                            ->options(self::ENTITY_TYPES)
                            ->multiple(),
                        NumberConstraint::make('practice_dates_start')
                            ->label('Practice start year')
                            ->integer(),
                        NumberConstraint::make('practice_dates_end')
                            ->label('Practice end year')
                            ->integer(),
                        // Feedback1 C1.2 — NTG date as a date constraint
                        // (before/after/between/is-set) inside the builder.
                        DateConstraint::make('ntg_date')
                            ->label('NTG date'),
                    ]),

                // Feedback1 Wave B (B2) — "worked between X and Y" helper. Two
                // optional year inputs; whichever bound is filled is applied.
                // A notary "worked in [from,to]" if their practice window
                // overlaps it: practice_dates_end >= from AND
                // practice_dates_start <= to. Nulls are treated as open-ended.
                Filter::make('practice_period')
                    ->label('Practice period')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->label('Worked after / from (year)')
                            ->numeric(),
                        Forms\Components\TextInput::make('to')
                            ->label('Worked before / to (year)')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, $from): Builder => $q->where(
                                fn (Builder $q) => $q
                                    ->whereNull('practice_dates_end')
                                    ->orWhere('practice_dates_end', '>=', (int) $from)
                            )
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $q, $to): Builder => $q->where(
                                fn (Builder $q) => $q
                                    ->whereNull('practice_dates_start')
                                    ->orWhere('practice_dates_start', '<=', (int) $to)
                            )
                        ))
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['from'])) {
                            $i[] = "Worked from {$data['from']}";
                        }
                        if (! empty($data['to'])) {
                            $i[] = "Worked to {$data['to']}";
                        }

                        return $i;
                    }),

                // Feedback1 Wave B (B2) — "has MS number" ternary on the
                // optional MS-prefixed alternative_identifier column.
                TernaryFilter::make('has_ms_number')
                    ->label('Has MS number')
                    ->placeholder('All creators')
                    ->trueLabel('Has MS number')
                    ->falseLabel('No MS number')
                    // TRIM parity so the two branches partition the set exactly:
                    // a whitespace-only alternative_identifier counts as "no MS
                    // number" on BOTH sides (without TRIM, a single space would
                    // slip into "has" but not "no").
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereRaw("TRIM(COALESCE(alternative_identifier, '')) <> ''"),
                        false: fn (Builder $q): Builder => $q->whereRaw("TRIM(COALESCE(alternative_identifier, '')) = ''"),
                        blank: fn (Builder $q): Builder => $q,
                    ),

                // Feedback1 C1.2 — "filter which creators worked as NTG ie:
                // have a NTG date associated". Presence of ntg_date == NTG.
                TernaryFilter::make('worked_as_ntg')
                    ->label('Worked as NTG')
                    ->placeholder('All creators')
                    ->trueLabel('Worked as NTG')
                    ->falseLabel('Never NTG')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('ntg_date'),
                        false: fn (Builder $q): Builder => $q->whereNull('ntg_date'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                // Feedback1 — hide row Delete when the creator still has
                // documents attached, so deleting one never orphans records.
                DeleteAction::make()
                    ->visible(fn (Authority $record): bool => ! $record->documents()->exists()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Feedback1 — a bulk delete that ignored the document guard
                    // would orphan documents. We override the action so it only
                    // deletes document-free creators in the selection and tells
                    // the operator how many were skipped.
                    DeleteBulkAction::make()
                        ->action(function (EloquentCollection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Authority $record */
                                if ($record->documents()->exists()) {
                                    $skipped++;

                                    continue;
                                }
                                $record->delete();
                                $deleted++;
                            }

                            $notification = Notification::make()
                                ->title($skipped === 0
                                    ? "Deleted {$deleted} creator(s)."
                                    : "Deleted {$deleted} creator(s); skipped {$skipped} that still have documents.");

                            ($skipped === 0 ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Build the entity_type Select options: the constrained vocabulary plus
     * any legacy value already stored on the record (so existing rows such
     * as 'PERSON' remain editable and saveable without data loss).
     *
     * @return array<string, string>
     */
    public static function entityTypeOptions(?string $current = null): array
    {
        $options = self::ENTITY_TYPES;

        if ($current !== null && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * Eager-load the first 'created' audit with its user so CreatorColumn
     * can render the inputter name without N+1 queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
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
            'index' => Pages\ListAuthorities::route('/'),
            'create' => Pages\CreateAuthority::route('/create'),
            'view' => Pages\ViewAuthority::route('/{record}'),
            'edit' => Pages\EditAuthority::route('/{record}/edit'),
        ];
    }
}
