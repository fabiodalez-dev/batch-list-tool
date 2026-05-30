<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\AuthorityResource\Pages;
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
use Filament\Tables\Table;
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
    private const FIELD_PERMISSIONS_KEY = 'authority';

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
                            ->rule(static function (Get $get): \Closure {
                                return static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    $start = $get('practice_dates_start');
                                    if ($value === null || $value === '' || $start === null || $start === '') {
                                        return;
                                    }
                                    if ((int) $value < (int) $start) {
                                        $fail('End year must be greater than or equal to the start year.');
                                    }
                                };
                            })),
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
            // Feedback1 — creators sorted by Identifier by default.
            ->defaultSort('identifier')
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
            ])
            ->filters([
                //
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
