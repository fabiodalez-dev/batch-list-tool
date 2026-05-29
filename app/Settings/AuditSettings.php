<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AuditSettings extends Settings
{
    public bool $enabled;

    public int $threshold;

    public static function group(): string
    {
        return 'audit';
    }
}
