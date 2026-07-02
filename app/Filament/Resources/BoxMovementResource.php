<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoxMovementResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\SearchableSelects;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BoxMovementResource extends Resource
{
    protected static ?string $model = BoxMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 15;

    /**
     * Bug #29 — a clearer document label for the movements table/infolist:
     * full identifier, the notary's full name, the volume number, and — when
     * the identifier is missing or auto-generated — the dates or a notes
     * excerpt so the operator can still tell which document moved.
     */
    public static function movementDocumentLabel(?Document $doc): ?string
    {
        if ($doc === null) {
            return null;
        }

        $parts = [];
        $id = $doc->display_identifier;
        $parts[] = ($id !== null && $id !== '') ? $id : 'No identifier';

        $authority = $doc->relationLoaded('authorities')
            ? $doc->authorities->first()
            : $doc->authorities()->first();
        if ($authority !== null) {
            $notary = trim(((string) $authority->getAttribute('given_names')) . ' ' . ((string) $authority->getAttribute('surname')));
            if ($notary !== '') {
                $parts[] = $notary;
            }
        }

        if (! empty($doc->volume_number)) {
            $parts[] = 'vol. ' . $doc->volume_number;
        }

        // Identifier unknown → surface dates / notes so the row stays identifiable.
        if ($id === null || $id === '' || str_starts_with((string) $id, 'AUTO-')) {
            $extra = $doc->dates ?: ($doc->notes ? Str::limit((string) $doc->notes, 60) : null);
            if ($extra) {
                $parts[] = (string) $extra;
            }
        }

        return implode(' — ', $parts);
    }

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        // All four FK Selects below use server-side autocomplete with
        // `preload(false)` — the documents (3,000+) and boxes (600+) tables
        // are too large to render as a flat `<select>` on the production
        // dataset. See App\Filament\Support\SearchableSelects.
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Document')
                    ->columns(1)
                    ->schema([
                        SearchableSelects::documentVia('document_id', 'document')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Movement')
                    ->columns($twoCols)
                    ->schema([
                        SearchableSelects::box('from_box_id', 'fromBox')
                            ->label('From box'),
                        SearchableSelects::box('to_box_id', 'toBox')
                            ->label('To box')
                            // Bug #28 — let the operator create the target box inline when
                            // it doesn't exist yet. Hardcoded to a RAS box: RAS needs no
                            // parent and no location/disinfestation preconditions (unlike
                            // IN_SITU/NRA) and isn't blocked from fresh creation (unlike the
                            // legacy MAV/STVC types), so the Box model guards are satisfied.
                            ->helperText('Pick an existing box, or use “Create” to add a new RAS box.')
                            ->createOptionForm([
                                SearchableSelects::batch('batch_id')
                                    ->label('Batch')
                                    ->required(),
                                Forms\Components\TextInput::make('box_number')
                                    ->label('Box number')
                                    ->required()
                                    ->maxLength(32)
                                    // Review finding: box_number is unique within a batch
                                    // (no DB unique index on it, only the BoxResource form
                                    // rule) — validate it here too so the inline create can't
                                    // persist a duplicate.
                                    ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                        $batchId = $get('batch_id');
                                        if ($batchId && Box::where('batch_id', $batchId)->where('box_number', $value)->exists()) {
                                            $fail("Box number {$value} already exists in this batch.");
                                        }
                                    }),
                                Forms\Components\TextInput::make('barcode')
                                    ->label('Barcode')
                                    ->required()
                                    ->maxLength(64)
                                    // Review finding: boxes.barcode is UNIQUE at the DB level —
                                    // validate here so a duplicate surfaces as an inline error
                                    // instead of an unhandled QueryException.
                                    ->unique(table: 'boxes', column: 'barcode'),
                            ])
                            ->createOptionUsing(fn (array $data): int => Box::create([
                                'batch_id' => $data['batch_id'],
                                'box_number' => $data['box_number'],
                                'barcode' => $data['barcode'],
                                'box_type' => 'RAS',
                                'barcode_status' => 'IN',
                            ])->getKey()),
                        Forms\Components\DateTimePicker::make('movement_date')
                            ->required(),
                        SearchableSelects::user('user_id', 'user'),
                        Forms\Components\TextInput::make('reason')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // → columnSpanFull. Every FK gets a ->url() to its Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Document')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('document.identifier')
                            ->label('Document')
                            // Bug #29 — same enriched label as the table.
                            ->state(fn (?BoxMovement $record): ?string => self::movementDocumentLabel($record?->document))
                            ->badge()
                            ->color('primary')
                            ->url(fn (?BoxMovement $record): ?string => $record?->document_id
                                ? route('filament.admin.resources.documents.view', ['record' => $record->document_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Movement')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('fromBox.box_number')
                            ->label('From box')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?BoxMovement $record): ?string => $record?->from_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->from_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('toBox.box_number')
                            ->label('To box')
                            ->badge()
                            ->color('success')
                            ->url(fn (?BoxMovement $record): ?string => $record?->to_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->to_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('movement_date')
                            ->label('When')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('user.name')
                            ->label('By user')
                            ->placeholder('—'),
                        TextEntry::make('reason')
                            ->label('Reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Audit info')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('updated_at')->dateTime()->label('Updated'),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.identifier')
                    ->label('Document')
                    // Bug #29 — identifier + notary + volume (+ dates/notes when
                    // the identifier is unknown), not just the bare identifier.
                    ->state(fn (BoxMovement $r): ?string => self::movementDocumentLabel($r->document))
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fromBox.box_number')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('toBox.box_number')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('movement_date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reason')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                CreatorColumn::make(),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                // Eager-load the relations the table columns render (avoid N+1).
                // Bug #29 — authorities feed the enriched document label.
                'document.authorities',
                'fromBox',
                'toBox',
                'user',
                'audits' => fn ($q) => $q->where('event', 'created')->with('user'),
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
            'index' => Pages\ListBoxMovements::route('/'),
            'create' => Pages\CreateBoxMovement::route('/create'),
            'view' => Pages\ViewBoxMovement::route('/{record}'),
            'edit' => Pages\EditBoxMovement::route('/{record}/edit'),
        ];
    }
}
