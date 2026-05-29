<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Settings\BackupSettings;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RFQ §3.1.8 — Backup health & retention settings page.
 *
 * Provides:
 *   - A read-only health summary (last backup file info, DB connectivity,
 *     backup disk free space).
 *   - A "Run backup now" header action that queues the spatie/laravel-backup
 *     `backup:run` Artisan command.
 *   - A small form to persist the retention settings from BackupSettings
 *     (keep_daily / keep_weekly / keep_monthly).
 *
 * Gated to super_admin / admin; viewers receive a 403 on mount.
 */
class BackupHealthPage extends Page
{
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

    protected static ?int $navigationSort = 95;

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

        $settings = app(BackupSettings::class);

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

        $settings = app(BackupSettings::class);
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

    // ─── actions ────────────────────────────────────────────────────────────

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackup')
                ->label('Run backup now')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Run database backup now?')
                ->modalDescription('This will queue a full database backup. The backup runs in the background — you can navigate away safely.')
                ->modalSubmitActionLabel('Yes, run backup')
                ->visible(fn () => static::canAccess())
                ->action(function () {
                    Artisan::queue('backup:run', ['--only-db' => false]);

                    Notification::make()
                        ->title('Backup queued')
                        ->body('The backup has been dispatched to the queue. Check the backup disk once the queue worker processes it.')
                        ->success()
                        ->send();
                }),

            Action::make('save')
                ->label('Save retention')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->action(fn () => $this->save()),
        ];
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
                'file' => basename((string) $latest),
                'date' => Carbon::createFromTimestamp($lastModified)->toDateTimeString(),
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

            return [
                'ok' => true,
                'free' => $this->formatBytes((int) $free),
                'total' => $this->formatBytes((int) $total),
                'percent_used' => $total > 0 ? round((1 - $free / $total) * 100, 1) : 0,
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
