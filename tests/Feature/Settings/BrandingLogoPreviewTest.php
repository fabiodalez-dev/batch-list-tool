<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BrandingPage;
use App\Models\User;
use App\Settings\BrandingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Task 1 — Branding logo preview + remove-logo action.
 *
 * Covers:
 *   - preview placeholder shows an <img> tag when logo_path is set
 *   - preview placeholder shows fallback text when no logo is set
 *   - remove_logo header action clears logo_path
 *   - normal save preserves existing logo when FileUpload is left empty
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ─── helpers ────────────────────────────────────────────────────────────────

function actAsAdmin_logoPreview(): User
{
    $u = User::factory()->create([
        'email' => 'logo-preview-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('renders an img tag in the logo preview placeholder when logo_path is set', function () {
    Storage::fake('public');

    $admin = actAsAdmin_logoPreview();
    $this->actingAs($admin);

    // Simulate an already-stored logo file on the public disk.
    Storage::disk('public')->put('branding/test-logo.png', 'fake-image-content');

    $settings = app(BrandingSettings::class);
    $settings->logo_path = 'branding/test-logo.png';
    $settings->save();

    // The page should render successfully and include the img tag.
    Livewire\Livewire::test(BrandingPage::class)
        ->assertStatus(200)
        ->assertSeeHtml('<img')
        ->assertSeeHtml('branding/test-logo.png');
});

it('renders fallback text in the logo preview placeholder when no logo is set', function () {
    Storage::fake('public');

    $admin = actAsAdmin_logoPreview();
    $this->actingAs($admin);

    $settings = app(BrandingSettings::class);
    $settings->logo_path = null;
    $settings->save();

    Livewire\Livewire::test(BrandingPage::class)
        ->assertStatus(200)
        ->assertSee('No logo set');
});

it('clears logo_path when the remove_logo header action is called', function () {
    Storage::fake('public');

    $admin = actAsAdmin_logoPreview();
    $this->actingAs($admin);

    // Set up a logo so the action is visible.
    Storage::disk('public')->put('branding/test-logo.png', 'fake-image-content');

    $settings = app(BrandingSettings::class);
    $settings->logo_path = 'branding/test-logo.png';
    $settings->save();

    Livewire\Livewire::test(BrandingPage::class)
        ->callAction('remove_logo')
        ->assertNotified();

    $fresh = app(BrandingSettings::class)->refresh();
    expect($fresh->logo_path)->toBeNull();
});

it('preserves existing logo when save is called with empty FileUpload', function () {
    Storage::fake('public');

    $admin = actAsAdmin_logoPreview();
    $this->actingAs($admin);

    Storage::disk('public')->put('branding/existing-logo.png', 'fake-image-content');

    $settings = app(BrandingSettings::class);
    $settings->logo_path = 'branding/existing-logo.png';
    $settings->save();

    // Save via form without touching logo_path (leave empty).
    Livewire\Livewire::test(BrandingPage::class)
        ->fillForm([
            'brand_name' => 'NRA Archive',
            // logo_path intentionally omitted (empty upload = keep current)
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $fresh = app(BrandingSettings::class)->refresh();
    // Logo path must be unchanged.
    expect($fresh->logo_path)->toBe('branding/existing-logo.png');
});
