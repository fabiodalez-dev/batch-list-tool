<?php

use App\Settings\AuditSettings;
use App\Settings\BackupSettings;
use App\Settings\BrandingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds branding/backup/audit defaults', function () {
    expect(app(BrandingSettings::class)->brand_name)->toBe('NAf')
        ->and(app(BrandingSettings::class)->logo_height)->toBe('2.5rem')
        ->and(app(BackupSettings::class)->keep_daily)->toBe(16)
        ->and(app(AuditSettings::class)->enabled)->toBeTrue();
});
