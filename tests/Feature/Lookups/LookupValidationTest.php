<?php

declare(strict_types=1);

use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Lookup\DigitisationStatus;
use App\Models\Lookup\FlagType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * RFQ §3.1.11 (part 2 of 3) — the ENUM-derived columns are validated against
 * the ACTIVE rows of the lookup tables, not the frozen PHP consts. These cover
 * the four contract cases: active code saves, deactivated code is rejected on a
 * NEW record, unknown code is rejected, and a pre-existing record carrying a
 * now-deactivated value still loads and can be re-saved.
 */

/*
|--------------------------------------------------------------------------
| Box.box_type / barcode_status
|--------------------------------------------------------------------------
*/

it('saves a box whose box_type + barcode_status are active lookup codes', function () {
    $box = Box::factory()->create(['box_type' => 'RAS', 'barcode_status' => 'IN']);

    expect($box->fresh()->box_type)->toBe('RAS');
});

it('rejects a NEW box with a deactivated barcode_status', function () {
    BarcodeStatus::where('code', 'OUT')->update(['is_active' => false]);

    Box::factory()->create(['barcode_status' => 'OUT']);
})->throws(ValidationException::class);

it('rejects a NEW box with an unknown box_type', function () {
    Box::factory()->create(['box_type' => 'FLOPPY']);
})->throws(ValidationException::class);

it('still loads + re-saves an existing box whose box_type was later deactivated', function () {
    // NRA is a valid, active type at insert time.
    $box = Box::factory()->create(['box_type' => 'RAS', 'barcode_status' => 'IN']);
    // RAS itself is the parent; create a child NRA box pointing at it.
    $child = Box::factory()->create([
        'box_type' => 'NRA',
        'parent_box_id' => $box->id,
        'batch_id' => $box->batch_id,
    ]);

    // Operator later retires the NRA lookup value.
    BoxType::where('code', 'NRA')->update(['is_active' => false]);

    // Existing record still loads.
    $reloaded = Box::find($child->id);
    expect($reloaded)->not->toBeNull();
    expect($reloaded->box_type)->toBe('NRA');

    // An unrelated edit that does NOT touch box_type still saves: the lookup
    // guard only fires when the column is dirty, so a deactivated legacy value
    // is never re-asserted on an unrelated update (expand, never restrict).
    $reloaded->notes = 'touched';
    $reloaded->save();

    expect($reloaded->fresh()->notes)->toBe('touched');
});

/*
|--------------------------------------------------------------------------
| DocumentFlag.type
|--------------------------------------------------------------------------
*/

it('saves a document flag whose type is an active lookup code', function () {
    $flag = DocumentFlag::factory()->create(['type' => 'damaged']);

    expect($flag->fresh()->type)->toBe('damaged');
});

it('rejects a NEW document flag with a deactivated type', function () {
    FlagType::where('code', 'damaged')->update(['is_active' => false]);

    DocumentFlag::factory()->create(['type' => 'damaged']);
})->throws(ValidationException::class);

it('rejects a NEW document flag with an unknown type', function () {
    DocumentFlag::factory()->create(['type' => 'totally_made_up']);
})->throws(ValidationException::class);

/*
|--------------------------------------------------------------------------
| Document.digitised / current_box_type
|--------------------------------------------------------------------------
*/

it('saves a document whose digitised + current_box_type are active lookup codes', function () {
    $doc = Document::factory()->create([
        'digitised' => 'VHMML',
        'current_box_type' => 'RAS Box',
    ]);

    expect($doc->fresh()->digitised)->toBe('VHMML');
    expect($doc->fresh()->current_box_type)->toBe('RAS Box');
});

it('rejects a NEW document with a deactivated digitised value', function () {
    DigitisationStatus::where('code', 'VHMML')->update(['is_active' => false]);

    Document::factory()->create(['digitised' => 'VHMML']);
})->throws(ValidationException::class);

it('rejects a NEW document with an unknown current_box_type', function () {
    // Unknown values are caught by the existing const-based enum guard
    // (DomainException); the lookup guard only governs the deactivated case.
    Document::factory()->create(['current_box_type' => 'Cardboard Tube']);
})->throws(DomainException::class);

it('still loads + re-saves an existing document whose current_box_type was later deactivated', function () {
    $doc = Document::factory()->create(['current_box_type' => 'Small Brown Box']);

    CurrentBoxType::where('code', 'Small Brown Box')->update(['is_active' => false]);

    $reloaded = Document::find($doc->id);
    expect($reloaded)->not->toBeNull();
    expect($reloaded->current_box_type)->toBe('Small Brown Box');

    // Unrelated edit still persists: dirty-checked guard does not re-assert the
    // unchanged (now-deactivated) current_box_type value.
    $reloaded->notes = 'edited';
    $reloaded->save();

    expect($reloaded->fresh()->notes)->toBe('edited');
});
