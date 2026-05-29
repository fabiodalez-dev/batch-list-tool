<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Branding
        $this->migrator->add('branding.brand_name', 'Batch List Tool');
        $this->migrator->add('branding.logo_path', 'images/brand-logo.png');
        $this->migrator->add('branding.logo_height', '2.25rem');
        $this->migrator->add('branding.primary_color', '#A5613D');

        // Backup
        $this->migrator->add('backup.keep_daily', 16);
        $this->migrator->add('backup.keep_weekly', 8);
        $this->migrator->add('backup.keep_monthly', 4);

        // Audit
        $this->migrator->add('audit.enabled', true);
        $this->migrator->add('audit.threshold', 0);
    }
};
