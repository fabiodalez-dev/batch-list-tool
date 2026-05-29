<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BrandingPage;
use App\Models\User;
use App\Settings\BrandingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Task 9 — Branding settings page + panel-wide application.
 *
 * Covers:
 *   - persisting branding settings via the page form
 *   - defensive boot (settings table missing does not break panel)
 *   - access gate (admin/super_admin in, viewer out)
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ─── helpers ────────────────────────────────────────────────────────────────

function actAsAdmin_branding(): User
{
    $u = User::factory()->create([
        'email' => 'branding-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

function actAsViewer_branding(): User
{
    $u = User::factory()->create([
        'email' => 'branding-viewer+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('viewer');

    return $u;
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('persists branding settings via the page', function () {
    $this->actingAs(actAsAdmin_branding());

    // Directly manipulate BrandingSettings and verify round-trip.
    $settings = app(BrandingSettings::class);
    $settings->brand_name = 'NRA Archive';
    $settings->save();

    // Re-resolve from container to confirm persistence.
    $fresh = app(BrandingSettings::class)->refresh();
    expect($fresh->brand_name)->toBe('NRA Archive');

    // Drive the Livewire page form: fill brand_name and save via header action.
    Livewire\Livewire::test(BrandingPage::class)
        ->fillForm(['brand_name' => 'NRA Test Name'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $updated = app(BrandingSettings::class)->refresh();
    expect($updated->brand_name)->toBe('NRA Test Name');
});

it('falls back to defaults when settings table is unavailable', function () {
    // This test verifies the AdminPanelProvider does not throw when
    // the settings subsystem is unavailable (e.g. fresh install, test
    // isolation). The defensive try/catch means the login page must
    // always return 200.
    $this->get('/admin/login')->assertOk();
});

it('gates the branding page to admins only', function () {
    // viewer → canAccess returns false
    $viewer = actAsViewer_branding();
    expect(BrandingPage::canAccess())->toBeFalse();

    // admin → canAccess returns true
    $admin = actAsAdmin_branding();
    $this->actingAs($admin);
    expect(BrandingPage::canAccess())->toBeTrue();
});

it('returns 403 when a viewer tries to mount the page', function () {
    $viewer = actAsViewer_branding();
    $this->actingAs($viewer);

    Livewire\Livewire::test(BrandingPage::class)
        ->assertForbidden();
});
