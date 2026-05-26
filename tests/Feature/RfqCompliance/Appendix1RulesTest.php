<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Support\BulkImport\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ Appendix 1 — Validation Rules. The five rules:
 *   #1 Batch numbers 33/34/36 cannot be used. Batch 50 reserved for wills.
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

/* ─────────── Appendix 1 #1 — Forbidden Batch numbers 33/34/36 ─────────── */

it('§ App.1 #1: Batch::FORBIDDEN_NUMBERS constant lists exactly [33, 34, 36]', function () {
    expect(Batch::FORBIDDEN_NUMBERS)->toBe([33, 34, 36]);
});

it('§ App.1 #1: EntityResolver::resolveBatch(33) returns forbidden marker', function () {
    $res = EntityResolver::resolveBatch(33);
    expect($res)->toHaveKey('forbidden')->and($res['forbidden'])->toBe(33);
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
