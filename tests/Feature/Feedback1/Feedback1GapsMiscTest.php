<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Lookup\BoxType;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 gaps (misc) — cosmetic / clarity fixes from client feedback:
 *
 *  1. Import modal placeholder reads "Upload an Excel or CSV file" via the
 *     lang/vendor/filament-actions/en/import.php partial override (the
 *     importers accept .xlsx as well as .csv).
 *  2. BoxResource `is_legacy` Toggle is no longer required on every box —
 *     it is only shown for legacy box types (MAV / STVC, driven by the
 *     box_types lookup is_legacy flag) or on records already flagged legacy.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function fgm_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $repo = Repository::factory()->create();
    $u = User::factory()->create([
        'email' => 'fgm-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $u;
}

// ===========================================================================
// 1. Import modal placeholder — lang override
// ===========================================================================

it('import modal file placeholder says "Upload an Excel or CSV file"', function () {
    expect(trans('filament-actions::import.modal.form.file.placeholder'))
        ->toBe('Upload an Excel or CSV file');
});

it('lang override merges with the vendor file instead of replacing it', function () {
    // Sibling key NOT present in lang/vendor/filament-actions/en/import.php —
    // must still resolve to the vendor default.
    expect(trans('filament-actions::import.modal.form.file.label'))
        ->toBe('File');
});

// ===========================================================================
// 2. Legacy box-type codes — lookup-driven with constant fallback
// ===========================================================================

it('legacyBoxTypeCodes falls back to Box::LEGACY_TYPES when the lookup is empty', function () {
    BoxType::query()->delete();

    expect(BoxResource::legacyBoxTypeCodes())->toBe(Box::LEGACY_TYPES);
});

it('legacyBoxTypeCodes is driven by the box_types lookup is_legacy flag', function () {
    BoxType::query()->delete();
    BoxType::create(['code' => 'RAS', 'label' => 'RAS', 'sort_order' => 1, 'is_active' => true, 'is_legacy' => false]);
    BoxType::create(['code' => 'MAV', 'label' => 'MAV', 'sort_order' => 2, 'is_active' => true, 'is_legacy' => true]);
    BoxType::create(['code' => 'STVC', 'label' => 'STVC', 'sort_order' => 3, 'is_active' => true, 'is_legacy' => true]);

    $codes = BoxResource::legacyBoxTypeCodes();

    expect($codes)->toContain('MAV')
        ->and($codes)->toContain('STVC')
        ->and($codes)->not->toContain('RAS');
});

// ===========================================================================
// 3. is_legacy Toggle no longer required — RAS box creates without it
// ===========================================================================

it('creates a RAS box without touching the is_legacy toggle (hidden, defaults to false)', function () {
    $user = fgm_actAsSuperAdmin();
    $this->actingAs($user);

    $batch = Batch::factory()->create(['repository_id' => $user->default_repository_id]);

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => $batch->id,
            'box_number' => '901',
            'barcode' => 'FGM-BC-' . uniqid(),
            'barcode_status' => 'IN',
            // is_legacy intentionally NOT filled — the toggle is hidden for
            // non-legacy box types and must no longer be required.
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $box = Box::where('box_number', '901')->where('batch_id', $batch->id)->first();

    expect($box)->not->toBeNull()
        ->and($box->is_legacy)->toBeFalse();
});
