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

/**
 * NAF Queries Q5 — box itemisation.
 *
 * Lets a document that stands for many physical items ("71 folders") be
 * expanded into an itemised list — added one at a time, or in bulk via the
 * "Itemise" header action (a count of placeholders, or a pasted/uploaded list,
 * one item per line). Items are reorderable; the operator's order is stored in
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
            ->reorderable('position')
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
                    ->modalDescription('Expand this record into individual items — enter a count of placeholder items, or paste a list (one item per line; use " | " or a tab to add a description).')
                    ->form([
                        Forms\Components\TextInput::make('count')
                            ->label('Number of placeholder items')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5000)
                            ->helperText('e.g. 71 to create "Folder 1"…"Folder 71".'),
                        Forms\Components\TextInput::make('prefix')
                            ->label('Placeholder prefix')
                            ->default('Folder')
                            ->maxLength(64),
                        Forms\Components\Textarea::make('lines')
                            ->label('…or paste a list (one item per line)')
                            ->rows(6),
                        Forms\Components\Toggle::make('replace')
                            ->label('Replace the existing itemised list')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var Document $owner */
                        $owner = $this->getOwnerRecord();
                        $replace = (bool) ($data['replace'] ?? false);

                        $lines = trim((string) ($data['lines'] ?? ''));
                        if ($lines !== '') {
                            $created = BoxItemisation::itemiseFromLines(
                                $owner,
                                preg_split('/\r\n|\r|\n/', $lines) ?: [],
                                $replace,
                            );
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
}
