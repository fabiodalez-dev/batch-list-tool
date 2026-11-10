<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/*
 * Client 2026-11-10: "can we also rename the whole tool Logistics tool as it
 * makes much more sense". The brand name is the tool's name in every page
 * title ("Dashboard - Logistics Tool") and the logo's alt text. A name an
 * administrator has changed on the Branding page is left as it is.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update(
            'branding.brand_name',
            static fn (?string $name): string => in_array(trim((string) $name), ['', 'NAf', 'NAF'], true) ? 'Logistics Tool' : (string) $name,
        );
    }

    public function down(): void
    {
        $this->migrator->update(
            'branding.brand_name',
            static fn (?string $name): string => $name === 'Logistics Tool' ? 'NAf' : (string) $name,
        );
    }
};
