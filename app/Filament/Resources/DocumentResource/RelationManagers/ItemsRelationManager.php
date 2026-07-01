<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use App\Models\Document;
use App\Support\BoxItemisation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * NAF Queries Q5 — box itemisation.
 *
 * Lets a document that stands for many physical items ("71 folders") be
 * expanded into an itemised list — added one at a time, or in bulk via the
 * "Itemise" header action (a count of placeholders, a pasted list, CSV/TXT, or
 * an Excel sheet). Items are reorderable; the operator's order is stored in
 * `position`. See {@see BoxItemisation}.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Itemised contents';

    protected static ?string $recordTitleAttribute = 'reference';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('reference')
                ->label('Reference')
                ->maxLength(128)
                ->helperText('Folder number / label for this item.'),

            Forms\Components\Textarea::make('description')
                ->label('Description')
                ->rows(2)
                ->maxLength(512),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('position')
            // Review finding: gate drag-reorder on the same write permission as
            // create/edit/delete — a view-only user must not mutate item order via
            // the reorderTable Livewire method.
            ->reorderable('position', condition: static::userCanUpdate())
            ->columns([
                Tables\Columns\TextColumn::make('position')
                    ->label('#')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => static::userCanUpdate())
                    ->mutateFormDataUsing(function (array $data): array {
                        /** @var Document $owner */
                        $owner = $this->getOwnerRecord();
                        $data['position'] = (int) $owner->items()->max('position') + 1;

                        return $data;
                    }),

                Action::make('itemise')
                    ->label('Itemise')
                    ->icon('heroicon-o-list-bullet')
                    ->color('primary')
                    ->visible(fn (): bool => static::userCanUpdate())
                    ->modalHeading('Itemise this document')
                    ->modalDescription('Expand this record into individual items — enter a count of placeholder items, paste a list, or upload a .xlsx/.csv/.txt sheet. Use the first column for the reference and the second for an optional description.')
                    ->form([
                        Forms\Components\TextInput::make('count')
                            ->label('Number of placeholder items')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5000)
                            ->helperText('e.g. 71 to create "Folder 1"…"Folder 71". Large counts create that many rows at once.'),
                        Forms\Components\TextInput::make('prefix')
                            ->label('Placeholder prefix')
                            ->default('Folder')
                            ->maxLength(64),
                        Forms\Components\Textarea::make('lines')
                            ->label('…or paste a list (one item per line)')
                            ->rows(6),
                        Forms\Components\FileUpload::make('file')
                            ->label('…or upload a sheet (.xlsx / .csv / .txt)')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                                'application/csv',
                            ])
                            ->storeFiles(false),
                        Forms\Components\Toggle::make('replace')
                            ->label('Replace the existing itemised list')
                            ->helperText('⚠ Deletes every existing itemised row for this document before adding the new ones. This cannot be undone.')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var Document $owner */
                        $owner = $this->getOwnerRecord();
                        $replace = (bool) ($data['replace'] ?? false);

                        $lines = trim((string) ($data['lines'] ?? ''));
                        $uploaded = self::uploadedLines($data['file'] ?? null);

                        if ($lines !== '') {
                            $created = BoxItemisation::itemiseFromLines(
                                $owner,
                                preg_split('/\r\n|\r|\n/', $lines) ?: [],
                                $replace,
                            );
                        } elseif ($uploaded !== []) {
                            $created = BoxItemisation::itemiseFromLines($owner, $uploaded, $replace);
                        } else {
                            $count = (int) ($data['count'] ?? 0);
                            $created = BoxItemisation::itemiseCount(
                                $owner,
                                $count,
                                trim((string) ($data['prefix'] ?? 'Folder')) ?: 'Folder',
                                $replace,
                            );
                        }

                        if ($created === 0) {
                            Notification::make()
                                ->title('Nothing to itemise')
                                ->body('Enter a count of at least 1, or paste at least one non-empty line.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($created . ' ' . ($created === 1 ? 'item' : 'items') . ' added')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                EditAction::make()->visible(fn (): bool => static::userCanUpdate()),
                DeleteAction::make()->visible(fn (): bool => static::userCanUpdate()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn (): bool => static::userCanUpdate()),
                ]),
            ]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can') && (bool) $user->can('view_any_document');
    }

    protected static function userCanUpdate(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return method_exists($user, 'can') && (bool) $user->can('update_document');
    }

    /**
     * Read an uploaded .xlsx/.csv/.txt (from the FileUpload, storeFiles(false)) into a
     * list of lines for {@see BoxItemisation::itemiseFromLines()}. Tolerates the
     * single-file, array, and empty shapes Filament can hand back.
     *
     * @return list<string>
     */
    protected static function uploadedLines(mixed $file): array
    {
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }
        if (! $file instanceof TemporaryUploadedFile) {
            return [];
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            // getRealPath() can return false at runtime for a missing temp file;
            // (string) normalises that to '' so the guard below stays honest.
            $path = (string) $file->getRealPath();

            return $path !== '' ? BoxItemisation::linesFromSpreadsheet($path) : [];
        }

        return preg_split('/\r\n|\r|\n/', (string) $file->get()) ?: [];
    }
}
