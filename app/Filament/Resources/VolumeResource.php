<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VolumeResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Document;
use App\Models\Volume;
use App\Support\CustomFields\CustomFieldSchema;
use Carbon\Carbon;
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

class VolumeResource extends Resource
{
    protected static ?string $model = Volume::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 14;

    protected static ?string $recordTitleAttribute = 'volume_number';

    /**
     * Wave D5 — Hide from navigation for all authenticated users.
     * Still discoverable via CLI (shield:generate, artisan) and Shield policy
     * generation because `canAccess()` / `canViewAny()` are not overridden.
     * Returning true for guests ensures CLI context (no auth) works normally.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->guest();
    }

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Searchable autocomplete (no preload): the documents table has
                        // 3,000+ rows in production, so a `<select>` with that many
                        // options is unusable. See App\Filament\Support\SearchableSelects.
                        // live() so the Custom fields Section re-renders when the operator
                        // picks a different document (which may belong to a different
                        // repository and thus expose different custom field definitions).
                        SearchableSelects::documentVia('document_id', 'document')
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('volume_number')
                            ->required()
                            ->maxLength(32)
                            ->columnSpanFull(),
                    ]),

                Section::make('Dates')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\DatePicker::make('dates_start'),
                        Forms\Components\DatePicker::make('dates_end'),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // Custom fields (EAV, per-repository).
                // For Volume the repository is derived from its document (spec §Architecture).
                //
                // Repository resolution order (GROUP A fix):
                //   1. Live form state: Document::find($get('document_id'))->repository_id
                //      document_id is ->live() (declared above) so the Section re-renders
                //      whenever the operator picks a different document.
                //   2. Fallback to the loaded record's document repository (on edit,
                //      before any document selection change).
                //   3. Fallback to the user's default repository (on create, no document yet).
                Section::make('Custom fields')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(static function (Get $get, ?Volume $record): array {
                        $documentId = $get('document_id');
                        $repositoryId = ($documentId ? Document::withoutGlobalScopes()->find($documentId)?->repository_id : null)
                            ?? $record?->document?->repository_id
                            ?? auth()->user()?->default_repository_id;

                        return CustomFieldSchema::for('volume', $repositoryId !== null ? (int) $repositoryId : null);
                    })
                    ->visible(static function (Get $get, ?Volume $record): bool {
                        $documentId = $get('document_id');
                        $repositoryId = ($documentId ? Document::withoutGlobalScopes()->find($documentId)?->repository_id : null)
                            ?? $record?->document?->repository_id
                            ?? auth()->user()?->default_repository_id;

                        return count(CustomFieldSchema::for('volume', $repositoryId !== null ? (int) $repositoryId : null)) > 0;
                    }),
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
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('document.identifier')
                            ->label('Document')
                            ->badge()
                            ->color('primary')
                            ->url(fn (?Volume $record): ?string => $record?->document_id
                                ? route('filament.admin.resources.documents.view', ['record' => $record->document_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('volume_number')
                            ->label('Volume number')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Dates')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('dates_start')
                            ->label('From')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('dates_end')
                            ->label('To')
                            ->date()
                            ->placeholder('—'),
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

                // Custom fields (EAV, per-repository) — view/infolist section.
                // For Volume the repository is derived from its document.
                Section::make('Custom fields')
                    ->columns($twoCols)
                    ->schema(static function (?Volume $record): array {
                        if (! $record instanceof Volume) {
                            return [];
                        }
                        $data = $record->getCustomFieldData();
                        if ($data === []) {
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
                    ->visible(static function (?Volume $record): bool {
                        if (! $record instanceof Volume) {
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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.identifier')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dates_start')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dates_end')
                    ->date()
                    ->sortable(),
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
                ...DocumentResource::customFieldTableColumns('volume'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVolumes::route('/'),
            'create' => Pages\CreateVolume::route('/create'),
            'view' => Pages\ViewVolume::route('/{record}'),
            'edit' => Pages\EditVolume::route('/{record}/edit'),
        ];
    }

    /**
     * Eager-load customFieldValues.definition to avoid N+1 in table columns.
     * Also load document so Volume::customFieldRepositoryId() can resolve via
     * document->repository_id without an additional query per row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customFieldValues.definition', 'document']);
    }
}
