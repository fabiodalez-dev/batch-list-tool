<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\SendToDisinfestationAction;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/*
|--------------------------------------------------------------------------
| RFQ Wave 2 — Task 7 (B1)
|--------------------------------------------------------------------------
|
| The BOX is the single source of truth for barcode status. The
| documents.barcode_status column is kept as a synced MIRROR (expand,
| never restrict). PERM_OUT requires a disinfestation record at the BOX
| level (A1.2 at box). MarkDisinfested must not silently revert a PERM_OUT
| box (B2 invariant, now at box level).
|
*/

// -- helpers -----------------------------------------------------------------

function bab_box(array $overrides = []): Box
{
    $repo = $overrides['__repo'] ?? Repository::factory()->create();
    unset($overrides['__repo']);
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    return Box::factory()->create(array_merge([
        'batch_id' => $batch->id,
        'barcode' => 'BC-AUTH-' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
    ], $overrides));
}

function bab_docInBox(Box $box, array $overrides = []): Document
{
    /** @var Batch $batch */
    $batch = $box->batch;

    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => Series::factory()->create()->id,
        'repository_id' => $batch->repository_id,
        'batch_id' => $batch->id,
        'current_box_id' => $box->id,
        'barcode_status' => 'IN',
    ], $overrides));
}

// 1 ---------------------------------------------------------------------------
test('changing boxes.barcode_status writes exactly one box_barcode_history row (old->new, user)', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);

    $box = bab_box(['barcode_status' => 'IN']);

    $box->update(['barcode_status' => 'OUT']);

    $rows = BoxBarcodeHistory::where('box_id', $box->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_status)->toBe('IN');
    expect($rows->first()->new_status)->toBe('OUT');
    expect($rows->first()->changed_by_user_id)->toBe($user->id);
});

// 2 ---------------------------------------------------------------------------
test('unrelated box update does NOT write a box_barcode_history row', function () {
    $box = bab_box();

    $box->update(['notes' => 'an unrelated note']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(0);
});

// 3 ---------------------------------------------------------------------------
test('changing box barcode_status mirrors onto every document in that box', function () {
    $box = bab_box(['barcode_status' => 'IN']);
    $d1 = bab_docInBox($box);
    $d2 = bab_docInBox($box);

    // A document in ANOTHER box must NOT be touched.
    $otherBox = bab_box(['barcode_status' => 'IN']);
    $dOther = bab_docInBox($otherBox);

    $box->update(['barcode_status' => 'OUT']);

    expect($d1->fresh()->barcode_status)->toBe('OUT');
    expect($d2->fresh()->barcode_status)->toBe('OUT');
    expect($dOther->fresh()->barcode_status)->toBe('IN');
});

// 4 ---------------------------------------------------------------------------
test('setting a box to PERM_OUT with no disinfestation_date is rejected at the box level', function () {
    $box = bab_box(['barcode_status' => 'IN', 'disinfestation_date' => null]);

    expect(fn () => $box->update(['barcode_status' => 'PERM_OUT']))
        ->toThrow(ValidationException::class);

    expect($box->fresh()->barcode_status)->toBe('IN');
});

// 5 ---------------------------------------------------------------------------
test('setting a box to PERM_OUT with a disinfestation_date is allowed and mirrors to docs', function () {
    $box = bab_box(['barcode_status' => 'IN', 'disinfestation_date' => now()->subDay()]);
    $doc = bab_docInBox($box, ['disinfestation_date' => now()->subDay()]);

    $box->update(['barcode_status' => 'PERM_OUT']);

    expect($box->fresh()->barcode_status)->toBe('PERM_OUT');
    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

// 6 ---------------------------------------------------------------------------
test('MarkDisinfested on a PERM_OUT box keeps PERM_OUT at the box level (B2 invariant)', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('super_admin')));

    $box = bab_box(['barcode_status' => 'PERM_OUT', 'disinfestation_date' => now()->subDay()]);
    $doc = bab_docInBox($box, [
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now()->subDay(),
    ]);

    $closure = (function () {
        return $this->action;
    })->call(MarkDisinfestedAction::make());

    $closure($doc, ['disinfestation_date' => now()->toDateString()]);

    // The box stays PERM_OUT — disinfesting must not silently pull it back IN.
    expect($box->fresh()->barcode_status)->toBe('PERM_OUT');
    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

// 7 ---------------------------------------------------------------------------
test('SendToDisinfestation flips the box (and mirrors docs) to OUT via box authority', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('super_admin')));

    $box = bab_box(['barcode_status' => 'IN']);
    $doc = bab_docInBox($box);

    $closure = (function () {
        return $this->action;
    })->call(SendToDisinfestationAction::make());

    $closure($doc);

    expect($box->fresh()->barcode_status)->toBe('OUT');
    expect($doc->fresh()->barcode_status)->toBe('OUT');
});

// 8 ---------------------------------------------------------------------------
test('a document with NO current box still gets its column written directly (fallback)', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($u) => $u->assignRole('super_admin')));

    $repo = Repository::factory()->create();
    $series = Series::factory()->create();
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'DOC-NB-' . strtoupper(substr(uniqid(), -6)),
        'document_type' => 'Register',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => null,
        'barcode_status' => 'IN',
    ]);

    $closure = (function () {
        return $this->action;
    })->call(SendToDisinfestationAction::make());

    $closure($doc);

    expect($doc->fresh()->barcode_status)->toBe('OUT');
});
