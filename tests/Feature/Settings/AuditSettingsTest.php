<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\AuditSettingsPage;
use App\Models\Series;
use App\Models\User;
use App\Settings\AuditSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

/**
 * Task 11 — Audit settings page.
 *
 * Covers:
 *   - access gate (super_admin only)
 *   - persisting audit settings via the page form
 *   - auditing respects the config['audit.enabled'] flag
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ─── helpers ────────────────────────────────────────────────────────────────

function actAsSuperAdmin_audit(): User
{
    $u = User::factory()->create([
        'email' => 'audit-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function actAsAdmin_audit(): User
{
    $u = User::factory()->create([
        'email' => 'audit-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('gates audit settings to super_admin only', function () {
    // admin → canAccess returns false
    $admin = actAsAdmin_audit();
    $this->actingAs($admin);
    expect(AuditSettingsPage::canAccess())->toBeFalse();

    // super_admin → canAccess returns true
    $superAdmin = actAsSuperAdmin_audit();
    $this->actingAs($superAdmin);
    expect(AuditSettingsPage::canAccess())->toBeTrue();
});

it('persists audit settings', function () {
    $superAdmin = actAsSuperAdmin_audit();
    $this->actingAs($superAdmin);

    // Confirm initial state
    $settings = resolve(AuditSettings::class);
    $settings->enabled = true;
    $settings->threshold = 0;
    $settings->save();

    // Drive the Livewire page form: disable auditing and set threshold.
    Livewire\Livewire::test(AuditSettingsPage::class)
        ->fillForm(['enabled' => false, 'threshold' => 5])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $fresh = resolve(AuditSettings::class)->refresh();
    expect($fresh->enabled)->toBeFalse();
    expect($fresh->threshold)->toBe(5);
});

it('stops writing audits when globally disabled', function () {
    // Enable console auditing so tests (which run via CLI) can produce audit rows.
    config(['audit.console' => true]);
    Audit::$auditingGloballyDisabled = false;

    $series = Series::factory()->create(['title' => 'Original Title']);
    $countBefore = Audit::count();

    $series->update(['title' => 'Updated Title']);

    expect(Audit::count())->toBeGreaterThan($countBefore);

    // Globally disable: simulates what AppServiceProvider does when
    // AuditSettings::enabled is false.
    Audit::$auditingGloballyDisabled = true;

    $countAfterDisable = Audit::count();
    $series->update(['title' => 'Another Title']);

    expect(Audit::count())->toBe($countAfterDisable);

    // Re-enable for test isolation.
    Audit::$auditingGloballyDisabled = false;
});
