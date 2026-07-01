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
use Filament\Schemas\Components\Section;
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
                // ── 1. Destination ──────────────────────────────────────────
                Section::make('Destination')
                    ->description('What this backup target is and whether it is active. Pick the storage type — the connection fields below adapt to it.')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText('A friendly name shown in the list, e.g. "Off-site FTP (Aruba)".'),

                        Select::make('driver')
                            ->label('Storage type')
                            ->required()
                            ->live()
                            ->native(false)
                            ->options([
                                'local' => 'Local (this server\'s disk)',
                                'ftp' => 'FTP',
                                'sftp' => 'SFTP (SSH)',
                                's3' => 'S3 / S3-compatible',
                            ])
                            ->helperText('Where the backup archives are sent. The fields under "Connection" change to match.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('When off, this destination is ignored by backups (kept for later).'),

                        Toggle::make('is_default')
                            ->label('Default destination')
                            ->helperText('The primary target highlighted in the Backup Center. Only one destination can be the default — setting this clears it on the others.'),
                    ]),

                // ── 2. Connection (driver-specific) ─────────────────────────
                Section::make('Connection')
                    ->description('Host, port and credentials for the selected storage type. Credentials are stored encrypted and never shown again after saving.')
                    ->icon('heroicon-o-key')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => filled($get('driver')))
                    ->schema([
                        // ---- FTP ----
                        TextInput::make('config.host')
                            ->label('Host')
                            ->placeholder('ftp.example.com')
                            ->helperText('Server hostname or IP address.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                        TextInput::make('config.port')
                            ->label('Port')
                            ->numeric()
                            ->default(21)
                            ->helperText('Default 21 for FTP.')
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
                            ->helperText('Stored encrypted. Leave blank when editing to keep the current one.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                        TextInput::make('config.root')
                            ->label('Root path')
                            ->placeholder('/backups')
                            ->helperText('Folder on the server where archives are written. Optional.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                        Toggle::make('config.ssl')
                            ->label('Use SSL (FTPS)')
                            ->helperText('Enable for an encrypted FTPS connection.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),
                        Toggle::make('config.passive')
                            ->label('Passive mode')
                            ->default(true)
                            ->helperText('Usually on — required behind most firewalls/NAT.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'ftp'),

                        // ---- SFTP ----
                        TextInput::make('config.host')
                            ->label('Host')
                            ->placeholder('sftp.example.com')
                            ->helperText('Server hostname or IP address.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                        TextInput::make('config.port')
                            ->label('Port')
                            ->numeric()
                            ->default(22)
                            ->helperText('Default 22 for SSH/SFTP.')
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
                            ->helperText('Use either a password OR a private key below. Stored encrypted.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                        Textarea::make('config.privateKey')
                            ->label('Private key')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Optional. Paste an SSH private key instead of a password.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                        TextInput::make('config.passphrase')
                            ->label('Key passphrase')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->placeholder('•••• (unchanged)')
                            ->helperText('Only if the private key is passphrase-protected. Stored encrypted.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),
                        TextInput::make('config.root')
                            ->label('Root path')
                            ->placeholder('/home/user/backups')
                            ->helperText('Folder on the server where archives are written. Optional.')
                            ->visible(fn (Get $get): bool => $get('driver') === 'sftp'),

                        // ---- S3 ----
                        TextInput::make('config.key')
                            ->label('Access key ID')
                            ->helperText('The S3 access key (IAM key for AWS).')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                        TextInput::make('config.secret')
                            ->label('Secret access key')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->placeholder('•••• (unchanged)')
                            ->helperText('Stored encrypted. Leave blank when editing to keep the current one.')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                        TextInput::make('config.region')
                            ->label('Region')
                            ->placeholder('eu-south-1')
                            ->helperText('AWS region, or as required by your S3 provider.')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                        TextInput::make('config.bucket')
                            ->label('Bucket')
                            ->helperText('Name of the bucket that stores the backups.')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                        TextInput::make('config.endpoint')
                            ->label('Endpoint')
                            ->placeholder('https://… (S3-compatible only)')
                            ->helperText('Leave blank for AWS S3. Set it for MinIO / Wasabi / other S3-compatible services.')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),
                        TextInput::make('config.root')
                            ->label('Root path')
                            ->placeholder('backups')
                            ->helperText('Optional prefix/folder inside the bucket.')
                            ->visible(fn (Get $get): bool => $get('driver') === 's3'),

                        // ---- Local ----
                        TextInput::make('config.root')
                            ->label('Root path')
                            ->placeholder('storage/app/backups')
                            ->helperText('Absolute or storage-relative folder on THIS server where archives are written.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('driver') === 'local'),
                    ]),

                // ── 3. Advanced ─────────────────────────────────────────────
                Section::make('Advanced')
                    ->description('Technical settings — the defaults are fine for most cases.')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('disk_key')
                            ->label('Disk key')
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->placeholder('auto-generated from the name')
                            ->helperText('Internal identifier (lowercase letters, numbers, underscores). Leave blank to generate it automatically from the name.')
                            ->dehydrateStateUsing(fn (?string $state): string => self::slugDiskKey((string) $state)),

                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Position in the destinations list (lower shows first).'),
                    ]),
            ]);
    }

    /**
     * Build a unique disk_key from a base string (the destination name),
     * appending a numeric suffix until it does not collide with an existing
     * destination. Used to auto-generate disk_key when the operator leaves it
     * blank, so the field can stay optional/advanced.
     */
    public static function uniqueDiskKey(string $base, ?int $ignoreId = null): string
    {
        $slug = self::slugDiskKey($base);

        if ($slug === '') {
            $slug = 'backup';
        }

        $candidate = $slug;
        $i = 2;

        while (
            BackupDestination::query()
                ->where('disk_key', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = $slug . '_' . $i;
            $i++;
        }

        return $candidate;
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
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('disk_key')
                    ->label('Disk key')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(),
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
