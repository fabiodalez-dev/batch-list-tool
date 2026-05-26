<?php

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the identifier change timeline on the Document edit/view page.
 *
 * Read-only by default; only admin / super_admin can append rows by hand
 * (e.g. to back-fill a missing transition).
 */
class IdentifierHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'identifierHistory';

    protected static ?string $title = 'Identifier history';

    protected static ?string $recordTitleAttribute = 'previous_identifier';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('previous_identifier')
                ->required()->maxLength(64),
            Forms\Components\TextInput::make('new_identifier')->maxLength(64),
            Forms\Components\DateTimePicker::make('changed_at')
                ->default(now())
                ->required(),
            Forms\Components\TextInput::make('reason')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('previous_identifier')
            ->defaultSort('changed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('previous_identifier')
                    ->label('From')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('new_identifier')
                    ->label('To')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('changed_at')
                    ->label('Changed')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('changedBy.name')
                    ->label('By')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('changed_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $v) => $q->whereDate('changed_at', '>=', $v),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($q, $v) => $q->whereDate('changed_at', '<=', $v),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['from'])) {
                            $i[] = 'Changed ≥ ' . $data['from'];
                        }
                        if (! empty($data['to'])) {
                            $i[] = 'Changed ≤ ' . $data['to'];
                        }

                        return $i;
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => static::userCanManage())
                    ->mutateFormDataUsing(function (array $data): array {
                        $owner = $this->getOwnerRecord();
                        $data['document_id'] = $owner->getKey();
                        $data['repository_id'] = $owner->repository_id;
                        $data['changed_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    /**
     * Only admin / super_admin can write rows by hand.
     */
    protected static function userCanManage(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')) {
            return $user->hasAnyRole(['super_admin', 'admin']);
        }

        return false;
    }
}
