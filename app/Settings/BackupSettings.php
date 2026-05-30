<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BackupSettings extends Settings
{
    public int $keep_daily;

    public int $keep_weekly;

    public int $keep_monthly;

    /**
     * Recipient email addresses for spatie/laravel-backup notifications.
     *
     * @var array<int, string>
     */
    public array $notify_emails;

    public bool $notify_on_success;

    public bool $notify_on_failure;

    public static function group(): string
    {
        return 'backup';
    }
}
