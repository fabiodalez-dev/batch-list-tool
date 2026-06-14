<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BrandingSettings extends Settings
{
    public string $brand_name;

    public ?string $logo_path = null;

    public string $logo_height;

    public string $primary_color;

    public static function group(): string
    {
        return 'branding';
    }
}
