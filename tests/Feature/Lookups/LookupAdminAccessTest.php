<?php

declare(strict_types=1);

use App\Filament\Resources\Lookups\BarcodeStatusResource;
use App\Models\Lookup\BarcodeStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

it('lets an administrator access the lookup resource', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
    expect(BarcodeStatusResource::canAccess())->toBeTrue();
});

it('denies a non-admin user', function () {
    $u = User::factory()->create();
    $u->assignRole('viewer');
    $this->actingAs($u);
    expect(BarcodeStatusResource::canAccess())->toBeFalse();
});

it('can create, deactivate and reorder a value', function () {
    $v = BarcodeStatus::create(['code' => 'TEMP', 'label' => 'Temp', 'sort_order' => 99, 'is_active' => true]);
    expect(BarcodeStatus::where('code', 'TEMP')->exists())->toBeTrue();
    $v->update(['is_active' => false]);
    expect(BarcodeStatus::active()->pluck('code'))->not->toContain('TEMP');
    $v->update(['sort_order' => 1]);
    expect((int) $v->fresh()->sort_order)->toBe(1);
});
