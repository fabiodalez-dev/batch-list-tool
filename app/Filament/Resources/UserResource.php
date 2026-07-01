<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use App\Support\RoleLabels;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * All assignable role slugs mapped to their RFQ display labels.
     *
     * super_admin is only visible to users who already hold that role —
     * an admin must not be able to grant (or even see) super_admin.
     *
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        $options = [];
        foreach (array_keys(RoleLabels::MAP) as $slug) {
            if ($slug === 'super_admin' && ! $isSuperAdmin) {
                continue;
            }
            $options[$slug] = RoleLabels::for($slug);
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2].
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identity')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ]),

                Section::make('Initial access')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->confirmed()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->maxLength(255)
                            ->helperText('Leave blank to keep the current password (edit only).'),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->password()
                            ->revealable()
                            ->dehydrated(false)
                            ->maxLength(255),
                        Forms\Components\Toggle::make('must_change_password')
                            ->label('Force password change on next login')
                            ->default(true)
                            ->disabled(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? 'New users always start with a forced password change.' : null),
                    ]),

                Section::make('Role & access')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->options(static::roleOptions())
                            ->required()
                            ->native(false)
                            // `role` is not a User column — it is synced into
                            // spatie/laravel-permission by the page classes.
                            ->dehydrated(false)
                            ->formatStateUsing(fn (?User $record): ?string => $record?->roles->first()?->name)
                            // Self-protection: cannot change own role (prevents accidental lock-out).
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                        Forms\Components\Select::make('repositories')
                            ->multiple()
                            ->relationship('repositories', 'name')
                            ->preload()
                            ->searchable(),
                        Forms\Components\Select::make('default_repository_id')
                            ->label('Default repository')
                            ->relationship('defaultRepository', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            // Self-protection: cannot deactivate own account (prevents lock-out).
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identity')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('name')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->copyable()
                            ->placeholder('—'),
                    ]),

                Section::make('Role & access')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => RoleLabels::for($state))
                            ->placeholder('—'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        IconEntry::make('must_change_password')
                            ->label('Must change password')
                            ->boolean(),
                        TextEntry::make('defaultRepository.name')
                            ->label('Default repository')
                            ->placeholder('—'),
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
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => RoleLabels::for($state)),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                // Bug #9 — uniform compact icon buttons so the actions cell keeps a
                // consistent width/alignment across rows (some rows hide toggleActive).
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
                Action::make('resetPassword')
                    ->label('Reset password')
                    ->iconButton()
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->action(function (User $record): void {
                        $tmp = Str::password(16);
                        $record->forceFill([
                            'password' => Hash::make($tmp),
                            'must_change_password' => true,
                        ])->save();

                        Notification::make()
                            ->title('Password reset')
                            ->body("Temporary password: {$tmp}")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->iconButton()
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is(auth()->user()) && (bool) auth()->user()?->can('update', $record))
                    ->action(function (User $record): void {
                        $record->forceFill(['is_active' => ! $record->is_active])->save();

                        Notification::make()
                            ->title($record->is_active ? 'User activated' : 'User deactivated')
                            ->success()
                            ->send();
                    }),
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
            RelationManagers\ActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
