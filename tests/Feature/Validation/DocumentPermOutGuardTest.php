<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
 * RFQ App.1 #5 (PERM_OUT requires a disinfestation date) was LOOSENED after
 * client feedback (Charlene Ellul, 2026-08-01): the legacy NAF data has many
 * PERM_OUT records with no disinfestation date, and the client confirmed those
 * must import as-is. Client feedback supersedes the RFQ because it is later.
 * The old model guard + DB CHECK were removed; these tests now pin the loosened
 * behaviour so the rule is not silently re-introduced.
 */

it('allows PERM_OUT without a disinfestation date (loosened, client feedback 2026-08-01)', function () {
    $doc = Document::factory()->create([
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => null,
    ]);

    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($doc->fresh()->disinfestation_date)->toBeNull();
});

it('still allows PERM_OUT together with a disinfestation date', function () {
    $doc = Document::factory()->create([
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now(),
    ]);
    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

it('allows a normal IN document with no disinfestation date', function () {
    $doc = Document::factory()->create([
        'barcode_status' => 'IN',
        'disinfestation_date' => null,
    ]);
    expect($doc->fresh()->barcode_status)->toBe('IN');
});
