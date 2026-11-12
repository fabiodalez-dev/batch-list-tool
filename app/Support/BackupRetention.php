<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\BackupSettings;
use Spatie\Backup\Config\Config;

/**
 * Applies the retention policy saved on the Backup & Health page
 * (BackupSettings keep_daily / keep_weekly / keep_monthly) to the
 * spatie/laravel-backup configuration that `backup:clean` reads.
 *
 * spatie builds its Config object from config('backup') and injects it into
 * the cleanup command and its strategy, so the stored values only take effect
 * if they are folded in when that object is built. AppServiceProvider wraps
 * the Config binding with withStoredRetention(); every value that is missing
 * or not a positive whole number keeps the config/backup.php default.
 */
final class BackupRetention
{
    /**
     * BackupSettings property => config('backup.cleanup.default_strategy') key.
     *
     * @var array<string, string>
     */
    private const array SETTING_TO_CONFIG = [
        'keep_daily' => 'keep_daily_backups_for_days',
        'keep_weekly' => 'keep_weekly_backups_for_weeks',
        'keep_monthly' => 'keep_monthly_backups_for_months',
    ];

    /**
     * The default cleanup strategy with the stored retention values applied.
     *
     * @param array<string, mixed> $strategy config('backup.cleanup.default_strategy')
     * @return array<string, mixed>
     */
    public static function applyTo(array $strategy): array
    {
        try {
            $settings = app(BackupSettings::class);
        } catch (\Throwable) {
            // Settings table not there yet (fresh install, early boot):
            // the config/backup.php values stay in force.
            return $strategy;
        }

        foreach (self::SETTING_TO_CONFIG as $property => $key) {
            try {
                $value = $settings->{$property};
            } catch (\Throwable) {
                continue;
            }

            if (is_int($value) && $value > 0) {
                $strategy[$key] = $value;
            }
        }

        return $strategy;
    }

    /**
     * Rebuild spatie's Config with the stored retention values, or return it
     * unchanged when they match the file configuration already.
     */
    public static function withStoredRetention(Config $config): Config
    {
        /** @var array<string, mixed> $backup */
        $backup = (array) config('backup', []);

        /** @var array<string, mixed> $strategy */
        $strategy = (array) data_get($backup, 'cleanup.default_strategy', []);

        $applied = self::applyTo($strategy);

        if ($applied === $strategy) {
            return $config;
        }

        data_set($backup, 'cleanup.default_strategy', $applied);

        return Config::fromArray($backup);
    }
}
