<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BackupSettings extends Settings
{
    public int $keep_daily;

    public int $keep_weekly;

    public int $keep_monthly;

    public static function group(): string
    {
        return 'backup';
    }
}
