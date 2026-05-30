<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Backup notification recipients + per-event toggles. Defaults keep the
        // existing behaviour conservative: notify on failure only, no recipients
        // configured until an admin sets them on the Backup settings page.
        $this->migrator->add('backup.notify_emails', []);
        $this->migrator->add('backup.notify_on_success', false);
        $this->migrator->add('backup.notify_on_failure', true);
    }

    public function down(): void
    {
        $this->migrator->delete('backup.notify_emails');
        $this->migrator->delete('backup.notify_on_success');
        $this->migrator->delete('backup.notify_on_failure');
    }
};
