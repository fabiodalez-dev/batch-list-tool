<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\Lookups\DigitisationStatusResource;
use App\Filament\Resources\VolumeResource;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Wave D5 — Navigation hiding for VolumeResource / DigitisationStatusResource,
 *           and form field removal for barcode / nra_location / museum_location.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wd5_sa(): User
{
    bl_seedShieldPermissions();

    $u = User::factory()->create([
        'email' => 'wd5-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    $repo = Repository::factory()->create();
    $u->repositories()->attach($repo->id, ['is_default' => true]);
    $u->update(['default_repository_id' => $repo->id]);

    return $u;
}

// ===========================================================================
// Nav hide assertions
// ===========================================================================

it('D5-Nav.1: VolumeResource::shouldRegisterNavigation returns false for an authenticated user', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    expect(VolumeResource::shouldRegisterNavigation())->toBeFalse();
});

it('D5-Nav.2: DigitisationStatusResource::shouldRegisterNavigation returns false for an authenticated user', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    expect(DigitisationStatusResource::shouldRegisterNavigation())->toBeFalse();
});

it('D5-Nav.3: VolumeResource::shouldRegisterNavigation returns true for guests (CLI/Shield discovery)', function (): void {
    // Ensure no user is authenticated — auth()->guest() === true.
    auth()->guard()->logout();

    expect(VolumeResource::shouldRegisterNavigation())->toBeTrue();
});

it('D5-Nav.4: DigitisationStatusResource::shouldRegisterNavigation returns true for guests', function (): void {
    auth()->guard()->logout();

    expect(DigitisationStatusResource::shouldRegisterNavigation())->toBeTrue();
});

// ===========================================================================
// DocumentResource form — removed fields
// ===========================================================================

it('D5-Form.1: DocumentResource create form does NOT contain barcode field', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldDoesNotExist('barcode');
});

it('D5-Form.2: DocumentResource create form does NOT contain nra_location field', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldDoesNotExist('nra_location');
});

it('D5-Form.3: DocumentResource create form does NOT contain museum_location field', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldDoesNotExist('museum_location');
});

it('D5-Form.4: DocumentResource create form still has location_id (canonical location)', function (): void {
    $user = wd5_sa();
    $this->actingAs($user);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldExists('location_id');
});
