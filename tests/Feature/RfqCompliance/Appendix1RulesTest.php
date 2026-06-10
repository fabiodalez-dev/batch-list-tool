<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Support\BulkImport\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/**
 * RFQ Appendix 1 — Validation Rules. The five rules:
 *   #1 Batch 34 and 36 are unused/forbidden; batch 33 is RESERVED for old MAV boxes (valid, not forbidden).
 *      Batch 50 reserved for wills.
 *   #2 Document cannot be marked PERM OUT unless it has a disinfestation date.
 *   #3 All In Situ boxes must reference a previous RAS box (unless explicitly NULL).
 *   #4 Legacy box types (MAV, STVC) cannot be created for new records.
 *   #5 More validation rules may be added in discussion with NAF (placeholder).
 *
 * Plus rule #2 from §2.2 glossary: Batch 50 = Wills (RWL, OWL series).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function app1_makeRepo(): Repository
{
    return Repository::factory()->create(['code' => 'A1-' . substr(uniqid(), -4)]);
}

function app1_makeBatch(int $repoId, int $n): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => $n >= 30 ? 'NOTARY_ACCESSION' : 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

/* ─────────── Appendix 1 #1 — Forbidden Batch numbers 34/36; 33 is reserved (valid) ─────────── */

it('§ App.1 #1: Batch::FORBIDDEN_NUMBERS constant lists exactly [34, 36] (batch 33 is reserved, not forbidden)', function () {
    expect(Batch::FORBIDDEN_NUMBERS)->toBe([34, 36])
        ->and(Batch::RESERVED_MAV_BATCH)->toBe(33);
});

it('§ App.1 #1: Batch(33)->isForbidden() is false; isReservedMav() is true', function () {
    $b = new Batch(['batch_number' => 33]);
    expect($b->isForbidden())->toBeFalse()
        ->and($b->isReservedMav())->toBeTrue();
});

it('§ App.1 #1: EntityResolver::resolveBatch(33) does NOT return forbidden marker (33 is reserved/valid)', function () {
    $res = EntityResolver::resolveBatch(33);
    // 33 is not in FORBIDDEN_NUMBERS — resolver returns null (batch not found in DB) rather than forbidden
    expect($res)->toBeNull();
});

it('§ App.1 #1: EntityResolver::resolveBatch(36) returns forbidden marker', function () {
    $res = EntityResolver::resolveBatch(36);
    expect($res)->toHaveKey('forbidden')->and($res['forbidden'])->toBe(36);
});

/* ─────────── Appendix 1 #2 — Wills (RWL/OWL) → batch 50 ─────────── */

it('§ App.1 #2: Batch::WILLS_BATCH constant equals 50', function () {
    expect(Batch::WILLS_BATCH)->toBe(50);
});

it('§ App.1 #2: Series::is_wills_series flag persists on RWL/OWL series', function () {
    $rwl = Series::create([
        'code' => 'RWL-' . substr(uniqid(), -4),
        'title' => 'Registers Private Practice Public Wills',
        'is_wills_series' => true, 'is_active' => true,
    ]);
    expect($rwl->is_wills_series)->toBeTrue();
});

it('§ App.1 #2: Batch(50)->isWillsOnly() returns true', function () {
    $b = new Batch(['batch_number' => 50]);
    expect($b->isWillsOnly())->toBeTrue();
});

/* ─────────── Appendix 1 #3 — IN_SITU/NRA box must have parent_box_id ─────────── */

it('§ App.1 #3: Box::requiresParent() returns true for IN_SITU and NRA types', function () {
    $boxA = new Box(['box_type' => 'IN_SITU']);
    $boxB = new Box(['box_type' => 'NRA']);
    expect($boxA->requiresParent())->toBeTrue()
        ->and($boxB->requiresParent())->toBeTrue();
});

it('§ App.1 #3: Box::requiresParent() returns false for RAS type', function () {
    $box = new Box(['box_type' => 'RAS']);
    expect($box->requiresParent())->toBeFalse();
});

/* ─────────── Appendix 1 #4 — MAV/STVC only with is_legacy=true ─────────── */

it('§ App.1 #4: Box::LEGACY_TYPES constant lists exactly [MAV, STVC]', function () {
    expect(Box::LEGACY_TYPES)->toBe(['MAV', 'STVC']);
});

it('§ App.1 #4: Box::TYPES includes MAV and STVC (legacy display) but mu-plugin gate applies', function () {
    expect(Box::TYPES)->toContain('MAV')
        ->and(Box::TYPES)->toContain('STVC')
        ->and(Box::TYPES)->toContain('RAS')
        ->and(Box::TYPES)->toContain('IN_SITU')
        ->and(Box::TYPES)->toContain('NRA');
});

/* ─────────── Appendix 1 #5 — PERM_OUT requires disinfestation_date ─────────── */

it('§ App.1 #5: Box::canBePermOut() returns false without disinfestation_date', function () {
    $box = new Box(['box_type' => 'RAS']);
    expect($box->canBePermOut())->toBeFalse();
});

it('§ App.1 #5: Box::canBePermOut() returns true with disinfestation_date', function () {
    $box = new Box(['box_type' => 'RAS', 'disinfestation_date' => '2026-05-01']);
    expect($box->canBePermOut())->toBeTrue();
});

/* ─────── RFQ-App1-R2-DOC — Document-level PERM_OUT requires disinfestation_date ──── */

it('§ App.1 R2-DOC: Document cannot be set PERM_OUT without a disinfestation_date', function () {
    $repo = app1_makeRepo();
    $batch = app1_makeBatch($repo->id, 41);
    $series = Series::create([
        'code' => 'APP1R2-' . substr(uniqid(), -4),
        'title' => 'Test Series',
        'is_active' => true,
        'is_wills_series' => false,
    ]);

    expect(fn () => Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DOC-APP1R2-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'series_id' => $series->id,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => null,
        'repository_id' => $repo->id,
    ]))->toThrow(ValidationException::class);
});

it('§ App.1 R2-DOC: Document can be set PERM_OUT when disinfestation_date is present', function () {
    $repo = app1_makeRepo();
    $batch = app1_makeBatch($repo->id, 42);
    $series = Series::create([
        'code' => 'APP1R2B-' . substr(uniqid(), -4),
        'title' => 'Test Series 2',
        'is_active' => true,
        'is_wills_series' => false,
    ]);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DOC-APP1R2OK-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'series_id' => $series->id,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-05-01',
        'repository_id' => $repo->id,
    ]);

    expect($doc->barcode_status)->toBe('PERM_OUT')
        ->and($doc->disinfestation_date)->not->toBeNull();
});

/* ─────── RFQ-App1-R3-EXPLICIT-NULL — provenance_unknown flag allows null parent ─── */

it('§ App.1 R3 explicit-NULL: IN_SITU box with provenance_unknown=true is accepted without parent', function () {
    $repo = app1_makeRepo();
    $batch = app1_makeBatch($repo->id, 43);

    // With provenance_unknown=true, no parent_box_id is required (RFQ App.1 #3 exception).
    $location = Location::withoutGlobalScopes()->create([
        'name' => 'TestLoc-' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    $box = Box::withoutGlobalScopes()->create([
        'box_type' => 'IN_SITU',
        'box_number' => 'PROV-UNK-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'barcode' => 'PROVUNK' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
        'is_legacy' => false,
        'parent_box_id' => null,
        'provenance_unknown' => true,
        'location_id' => $location->id,
    ]);

    expect($box->provenance_unknown)->toBeTrue()
        ->and($box->parent_box_id)->toBeNull();
});
