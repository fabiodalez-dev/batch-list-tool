<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Actions\Backup\RestoreDatabase;
use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\BackupDestinationResource;
use App\Models\BackupRun;
use App\Settings\BackupSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.8 — Backup Center (health, archives, on-demand runs & history).
 *
 * Provides:
 *   - A read-only health summary (last backup file info, DB connectivity,
 *     backup disk free/used space with an ok/warning/danger threshold).
 *   - A list of every `.zip` archive across all configured destination disks
 *     (listBackups()), each with a gated Download link and a confirmed Delete
 *     Livewire action — both guarded against directory traversal.
 *   - On-demand "Run backup now" / "Run DB-only" / "Run files-only" header
 *     actions that queue the spatie/laravel-backup `backup:run` command and
 *     record a "running" BackupRun row.
 *   - A run-history table (last ~15 BackupRun rows).
 *   - A form to persist the retention settings from BackupSettings
 *     (keep_daily / keep_weekly / keep_monthly).
 *
 * Gated to super_admin / admin; viewers receive a 403 on mount.
 *
 * @property-read Schema $form
 */
class BackupHealthPage extends Page
{
    use ExplainsPage;

    /**
     * Form state, bound to the Filament schema via statePath('data').
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Read-only health summary built in mount() and refreshed after each
     * action so the Blade view can display it without extra queries.
     *
     * @var array<string, mixed>
     */
    public array $health = [];

    protected string $view = 'filament.pages.settings.backup-health';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 60;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Backup & Health';

    protected static ?string $title = 'Backup & health';

    protected static ?string $slug = 'settings/backup-health';

    // ─── access ─────────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->guest()) {
            return true; // CLI / Shield discovery
        }

        return static::canAccess();
    }

    // ─── lifecycle ──────────────────────────────────────────────────────────

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = resolve(BackupSettings::class);

        $this->form->fill([
            'keep_daily' => $settings->keep_daily,
            'keep_weekly' => $settings->keep_weekly,
            'keep_monthly' => $settings->keep_monthly,
        ]);

        $this->health = $this->buildHealthSummary();
    }

    // ─── schema ─────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make('Retention policy')
                    ->description('How many daily, weekly and monthly backups to keep on each destination disk.')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        TextInput::make('keep_daily')
                            ->label('Keep daily backups')
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('days'),

                        TextInput::make('keep_weekly')
                            ->label('Keep weekly backups')
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('weeks'),

                        TextInput::make('keep_monthly')
                            ->label('Keep monthly backups')
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('months'),
                    ]),
            ]);
    }

    // ─── persistence ────────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $settings = resolve(BackupSettings::class);
        $settings->keep_daily = (int) $state['keep_daily'];
        $settings->keep_weekly = (int) $state['keep_weekly'];
        $settings->keep_monthly = (int) $state['keep_monthly'];
        $settings->save();

        Notification::make()
            ->title('Retention settings saved')
            ->body('The new retention policy will apply on the next cleanup run.')
            ->success()
            ->send();
    }

    // ─── backups list (per-disk archive listing) ──────────────────────────────

    /**
     * List every `.zip` backup archive across all configured destination disks.
     *
     * Each entry is a flat array the Blade view (and the restore action) can
     * render directly, plus the raw `disk` + `path` used to build Download /
     * Delete / Restore actions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(): array
    {
        /** @var array<int, string> $disks */
        $disks = (array) config('backup.backup.destination.disks', ['local']);
        $appName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));

        $rows = [];

        foreach ($disks as $diskName) {
            try {
                $disk = Storage::disk($diskName);

                foreach ($disk->allFiles($appName) as $file) {
                    if (! str_ends_with(strtolower($file), '.zip')) {
                        continue;
                    }

                    $size = $disk->size($file);
                    $modified = $disk->lastModified($file);

                    $rows[] = [
                        'filename' => basename($file),
                        'disk' => $diskName,
                        'path' => $file,
                        'size' => $this->formatBytes($size),
                        'size_bytes' => $size,
                        'date' => Date::createFromTimestamp($modified)->toDateTimeString(),
                        'timestamp' => $modified,
                    ];
                }
            } catch (\Throwable) {
                // Skip a disk that cannot be read; the health card surfaces the error.
                continue;
            }
        }

        // Most recent first.
        usort($rows, fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);

        return $rows;
    }

    /**
     * Recent backup runs for the history table.
     *
     * @return Collection<int, BackupRun>
     */
    public function recentRuns(): Collection
    {
        return BackupRun::recent()->limit(15)->get();
    }

    /**
     * Delete a single `.zip` backup archive (Livewire action, confirmed in UI).
     *
     * Applies the same path-traversal guard as the download controller:
     * disk allow-list, `.zip`-only, and a basename rebuild under the backup
     * directory so a crafted path can never escape it.
     */
    public function deleteBackup(string $disk, string $path): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var array<int, string> $disks */
        $disks = (array) config('backup.backup.destination.disks', ['local']);

        abort_unless(in_array($disk, $disks, true), 403, 'Invalid backup disk.');
        abort_unless(str_ends_with(strtolower($path), '.zip'), 403, 'Only .zip backups can be deleted.');
        abort_if(
            str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\'),
            403,
            'Invalid backup path.'
        );

        $appName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));
        $safePath = trim($appName, '/') . '/' . basename($path);

        $storage = Storage::disk($disk);

        if ($storage->exists($safePath)) {
            $storage->delete($safePath);

            Notification::make()
                ->title('Backup deleted')
                ->body(basename($safePath) . ' was removed from ' . $disk . '.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Backup not found')
                ->body('The selected backup no longer exists.')
                ->warning()
                ->send();
        }

        $this->health = $this->buildHealthSummary();
    }

    // ─── restore (super_admin ONLY) ──────────────────────────────────────────

    /**
     * The name of the database the application is currently connected to.
     * Used both as the value the operator must re-type to confirm a restore
     * and as the server-side comparison target.
     */
    public static function currentDatabaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config('database.connections.' . $connection . '.database', '');
    }

    /**
     * Restore row action for the backups list. super_admin ONLY — both via
     * UI visibility AND a server-side re-check inside the handler.
     */
    public function getRestoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            // UI visibility: super_admin ONLY (NOT admin).
            ->visible(fn () => (bool) auth()->user()?->hasRole('super_admin'))
            ->modalHeading('Restore database from this backup')
            ->modalDescription('This OVERWRITES the live database with the contents of the selected backup. A fresh DB safety snapshot is taken automatically before anything is overwritten. This cannot be undone.')
            ->modalSubmitActionLabel('Yes, overwrite the database')
            ->form([
                Checkbox::make('understand')
                    ->label('I understand this will OVERWRITE the live database')
                    ->accepted()
                    ->required(),

                TextInput::make('confirm_database')
                    ->label('Type the current database name to confirm')
                    ->helperText('Current database: ' . static::currentDatabaseName())
                    ->required()
                    ->autocomplete(false),
            ])
            ->action(fn (array $arguments, array $data) => $this->restoreFromBackup($arguments, $data));
    }

    /**
     * Handle a confirmed restore request.
     *
     * Defence in depth:
     *   1. server-side super_admin re-check (abort 403) — not just UI visibility;
     *   2. the typed database name must EXACTLY match the live DB name;
     *   3. the backup path is validated against traversal / disk allow-list.
     *
     * @param array<string, mixed> $arguments Row context (disk, path).
     * @param array<string, mixed> $data Submitted modal form data.
     */
    public function restoreFromBackup(array $arguments, array $data): void
    {
        // (1) Server-side guard — super_admin ONLY, regardless of UI visibility.
        abort_unless((bool) auth()->user()?->hasRole('super_admin'), 403);

        // (2) Typed-name confirmation must match the live DB name exactly.
        $typed = (string) ($data['confirm_database'] ?? '');
        $expected = static::currentDatabaseName();

        if ($typed !== $expected) {
            throw ValidationException::withMessages([
                'confirm_database' => 'The typed name does not match the current database name. Restore aborted.',
            ]);
        }

        $disk = (string) ($arguments['disk'] ?? '');
        $path = (string) ($arguments['path'] ?? '');

        // (3) Path / disk safety (same inline guard as deleteBackup()).
        /** @var array<int, string> $disks */
        $disks = config('backup.backup.destination.disks', ['local']);
        abort_unless(in_array($disk, $disks, true), 403, 'Invalid backup disk.');
        abort_unless(str_ends_with(strtolower($path), '.zip'), 403, 'Only .zip backups can be restored.');
        abort_if(
            str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\'),
            403,
            'Invalid backup path.'
        );

        // Pass the disk + relative path (NOT a local filesystem path): the
        // restore service streams the archive off whatever disk it lives on
        // (local/ftp/sftp/s3) into a local temp file, so restoring from a
        // remote destination works too.
        try {
            $run = resolve(RestoreDatabase::class)->restore($disk, $path, auth()->id());

            Notification::make()
                ->title('Database restored')
                ->body($run->message ?? 'The database was restored from the selected backup.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Restore failed')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }

        $this->health = $this->buildHealthSummary();
    }

    // ─── actions ────────────────────────────────────────────────────────────

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Discoverability: the host/port/credentials of FTP/SFTP/S3 backup
            // targets are configured in the separate "Backup destinations"
            // resource, not on this page. Link straight to it.
            Action::make('configureDestinations')
                ->label('Configure destinations')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('gray')
                ->url(fn (): string => BackupDestinationResource::getUrl())
                ->visible(fn (): bool => static::canAccess()
                    && BackupDestinationResource::canAccess()),

            Action::make('runBackup')
                ->label('Run backup now')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Run backup now?')
                ->modalDescription('This will queue a full backup (files + database). The backup runs in the background — you can navigate away safely.')
                ->modalSubmitActionLabel('Yes, run backup')
                ->visible(fn () => static::canAccess())
                ->action(fn () => $this->queueBackup('full', 'backup:run')),

            Action::make('runDbBackup')
                ->label('Run DB-only')
                ->icon('heroicon-o-circle-stack')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run database-only backup?')
                ->modalDescription('This will queue a backup of the database only (no files). It runs in the background.')
                ->modalSubmitActionLabel('Yes, back up the database')
                ->visible(fn () => static::canAccess())
                ->action(fn () => $this->queueBackup('db', 'backup:run', ['--only-db' => true])),

            Action::make('runFilesBackup')
                ->label('Run files-only')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run files-only backup?')
                ->modalDescription('This will queue a backup of the application files only (no database). It runs in the background.')
                ->modalSubmitActionLabel('Yes, back up the files')
                ->visible(fn () => static::canAccess())
                ->action(fn () => $this->queueBackup('files', 'backup:run', ['--only-files' => true])),

            Action::make('save')
                ->label('Save retention')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->action(fn () => $this->save()),
        ];
    }

    /**
     * Queue a backup run via Artisan and record a "running" BackupRun row.
     *
     * @param 'full'|'db'|'files' $type
     * @param array<string, mixed> $parameters
     */
    protected function queueBackup(string $type, string $command, array $parameters = []): void
    {
        abort_unless(static::canAccess(), 403);

        Artisan::queue($command, $parameters);

        /** @var array<int, string> $disks */
        $disks = (array) config('backup.backup.destination.disks', ['local']);

        BackupRun::create([
            'type' => $type,
            'destination_disk' => $disks[0] ?? 'local',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by_user_id' => Auth::id(),
            'message' => 'Queued from Backup Center',
        ]);

        $label = match ($type) {
            'db' => 'Database backup queued',
            'files' => 'Files backup queued',
            default => 'Backup queued',
        };

        Notification::make()
            ->title($label)
            ->body('The backup has been dispatched to the queue. Check the run history once the queue worker processes it.')
            ->success()
            ->send();

        $this->health = $this->buildHealthSummary();
    }

    // ─── health summary ─────────────────────────────────────────────────────

    /**
     * Build a read-only health snapshot for the Blade view.
     * All I/O is wrapped in try/catch so a missing backup directory or an
     * unavailable DB never breaks the page render.
     *
     * @return array<string, mixed>
     */
    protected function buildHealthSummary(): array
    {
        return [
            'db' => $this->checkDbConnection(),
            'backup' => $this->checkLatestBackup(),
            'disk' => $this->checkDiskFreeSpace(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDbConnection(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true, 'message' => 'Connected'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkLatestBackup(): array
    {
        try {
            /** @var array<int, string> $disks */
            $disks = config('backup.backup.destination.disks', ['local']);
            $diskName = $disks[0] ?? 'local';

            $appName = config('backup.backup.name', config('app.name', 'laravel-backup'));
            $disk = Storage::disk($diskName);

            $files = collect($disk->allFiles($appName))
                ->filter(fn (string $f) => str_ends_with($f, '.zip'))
                ->sort()
                ->values();

            if ($files->isEmpty()) {
                return ['ok' => false, 'message' => 'No backups found', 'file' => null, 'date' => null, 'size' => null];
            }

            $latest = $files->last();
            $lastModified = $disk->lastModified($latest);
            $size = $disk->size($latest);

            return [
                'ok' => true,
                'message' => 'Backup found',
                'file' => basename($latest),
                'date' => Date::createFromTimestamp($lastModified)->toDateTimeString(),
                'size' => $this->formatBytes($size),
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Could not read backup disk', 'file' => null, 'date' => null, 'size' => null];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDiskFreeSpace(): array
    {
        try {
            $storagePath = storage_path();
            $free = disk_free_space($storagePath);
            $total = disk_total_space($storagePath);

            if ($free === false || $total === false) {
                return ['ok' => false, 'message' => 'Could not determine disk space'];
            }

            $percentUsed = $total > 0 ? round((1 - $free / $total) * 100, 1) : 0.0;

            // Threshold: ok < 75 %, warning 75–90 %, danger > 90 %.
            $status = match (true) {
                $percentUsed > 90 => 'danger',
                $percentUsed >= 75 => 'warning',
                default => 'ok',
            };

            return [
                'ok' => true,
                'free' => $this->formatBytes((int) $free),
                'total' => $this->formatBytes((int) $total),
                'percent_used' => $percentUsed,
                'status' => $status,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Could not determine disk space'];
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;

        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }

        return round($b, $precision) . ' ' . $units[$i];
    }
}
