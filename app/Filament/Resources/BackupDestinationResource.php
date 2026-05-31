<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BackupDestinationResource\Pages;
use App\Models\BackupDestination;
use App\Support\BackupDestinations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class BackupDestinationResource extends Resource
{
    /**
     * Secret config keys that are write-only: never hydrated to the browser on
     * edit, and only persisted when the user actually types a new value.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = ['password', 'passphrase', 'secret'];

    protected static ?string $model = BackupDestination::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 95;

    public static function getModelLabel(): string
    {
        return 'Backup destination';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Backup destinations';
    }

    public static function getNavigationLabel(): string
    {
        return 'Backup destinations';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('driver')
                    ->required()
                    ->live()
                    ->options([
                        'local' => 'Local',
                        'ftp' => 'FTP',
                        'sftp' => 'SFTP',
                        's3' => 'S3',
                    ]),

                TextInput::make('disk_key')
                    ->label('Disk key')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->helperText('Lowercase letters, numbers and underscores only.')
                    ->dehydrateStateUsing(fn (?string $state): string => self::slugDiskKey((string) $state)),

                Toggle::make('is_active')
                    ->default(true),

                Toggle::make('is_default'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                // ---- FTP ----
                TextInput::make('config.host')
                    ->label('Host')
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                TextInput::make('config.port')
                    ->label('Port')
                    ->numeric()
                    ->default(21)
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                TextInput::make('config.username')
                    ->label('Username')
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                TextInput::make('config.password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->placeholder('•••• (unchanged)')
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                TextInput::make('config.root')
                    ->label('Root path')
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                Toggle::make('config.ssl')
                    ->label('SSL')
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                Toggle::make('config.passive')
                    ->label('Passive mode')
                    ->default(true)
                    ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),

                // ---- SFTP ----
                TextInput::make('config.host')
                    ->label('Host')
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                TextInput::make('config.port')
                    ->label('Port')
                    ->numeric()
                    ->default(22)
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                TextInput::make('config.username')
                    ->label('Username')
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                TextInput::make('config.password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->placeholder('•••• (unchanged)')
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                Textarea::make('config.privateKey')
                    ->label('Private key')
                    ->rows(4)
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                TextInput::make('config.passphrase')
                    ->label('Passphrase')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->placeholder('•••• (unchanged)')
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                TextInput::make('config.root')
                    ->label('Root path')
                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),

                // ---- S3 ----
                TextInput::make('config.key')
                    ->label('Access key')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                TextInput::make('config.secret')
                    ->label('Secret key')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->placeholder('•••• (unchanged)')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                TextInput::make('config.region')
                    ->label('Region')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                TextInput::make('config.bucket')
                    ->label('Bucket')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                TextInput::make('config.endpoint')
                    ->label('Endpoint')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                TextInput::make('config.root')
                    ->label('Root path')
                    ->visible(fn (Get $get): bool => $get('driver') === 's3'),

                // ---- Local ----
                TextInput::make('config.root')
                    ->label('Root path')
                    ->visible(fn (Get $get): bool => $get('driver') === 'local'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('driver')
                    ->badge()
                    ->sortable(),
                TextColumn::make('disk_key')
                    ->label('Disk key')
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->action(function (BackupDestination $record): void {
                        $result = BackupDestinations::testConnection($record);

                        $notification = Notification::make()
                            ->title($result['ok'] ? 'Connection OK' : 'Connection failed')
                            ->body($result['message']);

                        $result['ok']
                            ? $notification->success()->send()
                            : $notification->danger()->send();
                    }),
                Action::make('setDefault')
                    ->label('Set default')
                    ->icon('heroicon-o-star')
                    ->visible(fn (BackupDestination $record): bool => ! $record->is_default)
                    ->action(function (BackupDestination $record): void {
                        BackupDestination::query()
                            ->where('id', '!=', $record->getKey())
                            ->update(['is_default' => false]);

                        $record->forceFill(['is_default' => true])->save();

                        Notification::make()
                            ->title('Default destination updated')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackupDestinations::route('/'),
            'create' => Pages\CreateBackupDestination::route('/create'),
            'edit' => Pages\EditBackupDestination::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return self::userIsAdmin();
    }

    public static function canViewAny(): bool
    {
        return self::userIsAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::userIsAdmin();
    }

    /**
     * Normalise a disk key to lowercase letters, numbers and underscores.
     */
    public static function slugDiskKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    private static function userIsAdmin(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }
}
