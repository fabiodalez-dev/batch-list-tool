<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave C2.1 / C2.2 / C2.4 — conditional RAS vs In-Situ box form,
 * the RAS status-transition rule (PERM OUT archives, IN requires a new
 * barcode) and the destroy-date guard. Drives the real Filament Create/Edit
 * Livewire pages plus the model-level guards.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function bcf_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $repo = Repository::factory()->create();
    $u = User::factory()->create([
        'email' => 'bcf-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $u;
}

it('RAS box requires batch, box_number and barcode through the create form', function () {
    $this->actingAs(bcf_actAsSuperAdmin());

    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'RAS',
            'batch_id' => null,
            'box_number' => null,
            'barcode' => null,
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['batch_id', 'box_number', 'barcode']);
});

it('In-Situ box requires identifier, location and barcode (A10) but NOT batch', function () {
    $this->actingAs(bcf_actAsSuperAdmin());

    // A10 (Wave A) — barcode is now required for ALL box types, including
    // IN_SITU. Missing box_number, location_id and barcode → those three error;
    // batch_id is still NOT required for IN_SITU boxes.
    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'IN_SITU',
            'provenance_unknown' => true, // skip the RAS-parent requirement
            'box_number' => null,
            'location_id' => null,
            'batch_id' => null,
            'barcode' => null,
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['box_number', 'location_id', 'barcode'])
        ->assertHasNoFormErrors(['batch_id']);
});

it('creates a valid In-Situ box with identifier, location and barcode and no batch', function () {
    $user = bcf_actAsSuperAdmin();
    $this->actingAs($user);

    $location = Location::factory()->create([
        'repository_id' => $user->default_repository_id,
        'is_active' => true,
    ]);

    // A10 (Wave A) — barcode is now required for all box types.
    Livewire::test(CreateBox::class)
        ->fillForm([
            'box_type' => 'IN_SITU',
            'provenance_unknown' => true,
            'box_number' => 'NRA1',
            'location_id' => $location->id,
            'is_legacy' => false,
            'barcode_status' => 'IN',
            'barcode' => 'NRA-BC-' . uniqid(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Box::where('box_number', 'NRA1')->where('box_type', 'IN_SITU')->exists())->toBeTrue();
});

it('archives the barcode to history when a RAS box transitions to PERM OUT', function () {
    $this->actingAs(bcf_actAsSuperAdmin());

    $box = Box::factory()->create([
        'box_type' => 'RAS',
        'barcode' => 'OLD-BC-1',
        'barcode_status' => 'IN',
        'disinfestation_date' => now()->subDay(),
    ]);

    expect($box->barcodeHistory()->count())->toBe(0);

    // RFQ-3.1.7-A: PERM_OUT requires a location on existing boxes.
    $loc = Location::withoutGlobalScopes()->create([
        'name' => 'NRA-HIST-LOC',
        'type' => 'room',
        'repository_id' => $box->batch->repository_id,
        'is_active' => true,
    ]);
    $box->update(['barcode_status' => 'PERM_OUT', 'location_id' => $loc->id]);

    // The existing barcode-history observer logs the IN → PERM_OUT transition.
    $history = $box->barcodeHistory()->get();
    expect($history)->toHaveCount(1)
        ->and($history->first()->previous_status)->toBe('IN')
        ->and($history->first()->new_status)->toBe('PERM_OUT');
});

it('requires a NEW barcode (present and different) when a box re-enters with status IN after PERM OUT', function () {
    $this->actingAs(bcf_actAsSuperAdmin());

    // The "new barcode on IN" rule applies to a PERM_OUT re-entry: PERM_OUT
    // archives the barcode to history, so re-entering needs a fresh one. (A
    // plain OUT→IN — e.g. the disinfestation out-and-back — keeps its barcode
    // and is exercised by the disinfestation mirror tests.)
    $box = Box::factory()->create([
        'box_type' => 'RAS',
        'barcode' => 'BC-RE-1',
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now()->toDateString(), // PERM_OUT precondition
    ]);

    // Re-enter as IN re-using the SAME barcode → rejected.
    expect(fn () => $box->update(['barcode_status' => 'IN']))
        ->toThrow(ValidationException::class);

    // Still PERM_OUT (the save was rejected).
    expect($box->fresh()->barcode_status)->toBe('PERM_OUT');

    // Re-enter as IN with a NEW barcode → accepted.
    $box->update(['barcode_status' => 'IN', 'barcode' => 'BC-NEW-2']);
    expect($box->fresh()->barcode_status)->toBe('IN')
        ->and($box->fresh()->barcode)->toBe('BC-NEW-2');
});

it('rejects a box marked destroyed without a destroy date (model guard)', function () {
    $this->actingAs(bcf_actAsSuperAdmin());

    $box = Box::factory()->create(['box_type' => 'RAS']);

    // A destruction reason without a destroy date is rejected.
    expect(fn () => $box->update(['destroyed_reason' => 'Crushed at depot']))
        ->toThrow(ValidationException::class);

    // With a date it saves.
    $box->update([
        'destroyed_reason' => 'Crushed at depot',
        'destroyed_at' => now(),
    ]);
    expect($box->fresh()->isDestroyed())->toBeTrue();
});

it('requires the destroy date in the edit form once a reason is entered', function () {
    $user = bcf_actAsSuperAdmin();
    $this->actingAs($user);

    $batch = Batch::factory()->create(['repository_id' => $user->default_repository_id]);
    $box = Box::factory()->create([
        'box_type' => 'RAS',
        'batch_id' => $batch->id,
        'barcode' => 'BC-DESTROY-1',
        'barcode_status' => 'IN',
    ]);

    Livewire::test(EditBox::class, ['record' => $box->getRouteKey()])
        ->fillForm([
            'destroyed_reason' => 'Incinerated',
            'destroyed_at' => null,
        ])
        ->call('save')
        ->assertHasFormErrors(['destroyed_at']);
});

it('does NOT persist a manually-filled barcode history row — the log is read-only (F3)', function () {
    $user = bcf_actAsSuperAdmin();
    $this->actingAs($user);

    $batch = Batch::factory()->create(['repository_id' => $user->default_repository_id]);
    $box = Box::factory()->create([
        'box_type' => 'RAS',
        'batch_id' => $batch->id,
        'barcode' => 'BC-HIST-1',
        'barcode_status' => 'IN',
    ]);

    // F3: the barcodeHistory Repeater is disabled + not dehydrated, so a
    // manually-filled row must NOT reach the DB. The audit log is written
    // exclusively by the model observer (recordBarcodeChange). Submitting a
    // forged row through the form is a no-op.
    Livewire::test(EditBox::class, ['record' => $box->getRouteKey()])
        ->fillForm([
            'barcodeHistory' => [
                [
                    'previous_barcode' => 'LEGACY-BC-9',
                    'new_barcode' => 'BC-HIST-1',
                    'previous_status' => 'OUT',
                    'new_status' => 'IN',
                    'changed_at' => now(),
                    'reason' => 'Back-fill legacy barcode',
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($box->barcodeHistory()->where('previous_barcode', 'LEGACY-BC-9')->exists())->toBeFalse();
});
