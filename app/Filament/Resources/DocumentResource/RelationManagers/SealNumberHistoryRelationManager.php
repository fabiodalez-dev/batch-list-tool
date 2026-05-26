<?php

namespace App\Filament\Resources\DocumentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the seal_number change timeline on the Document edit/view page.
 *
 * Read-only: no inline create / edit / delete is offered, because the rows
 * are appended automatically by the Document model's `updating` hook
 * (RFQ §3.1.5 — seal-number chain-of-custody). The header CreateAction
 * remains hidden by default; only admin / super_admin can append rows by
 * hand (e.g. to back-fill a missing transition during data migration).
 */
class SealNumberHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'sealNumberHistory';

    protected static ?string $title = 'Seal number history';

    protected static ?string $recordTitleAttribute = 'previous_seal_number';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('previous_seal_number')
                ->required()->maxLength(50),
            Forms\Components\TextInput::make('new_seal_number')->maxLength(50),
            Forms\Components\DateTimePicker::make('changed_at')
                ->default(now())
                ->required(),
            Forms\Components\TextInput::make('reason')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('previous_seal_number')
            ->defaultSort('changed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('previous_seal_number')
                    ->label('From')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('new_seal_number')
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
                Tables\Actions\CreateAction::make()
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
                Tables\Actions\ViewAction::make(),
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
