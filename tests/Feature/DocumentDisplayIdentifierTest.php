<?php

declare(strict_types=1);

use App\Models\Document;

/**
 * RFQ Appendix-2 §xv — `object_reference_number` is "a temporary identifier
 * used in past projects" and should act as a FALLBACK when the canonical
 * `catalogue_identifier` is null. The legacy POC `identifier` column is the
 * last-resort fallback.
 *
 * The accessor is pure model-level computation — no DB roundtrip needed —
 * so these tests build unsaved Document instances and assert the resolution
 * order directly. Avoids RefreshDatabase overhead and pins the contract at
 * the model layer where it belongs.
 */
test('display_identifier returns catalogue_identifier when set', function () {
    $doc = new Document([
        'catalogue_identifier' => 'CAT-123',
        'object_reference_number' => 'OBJ-001',
        'identifier' => 'R45',
    ]);

    expect($doc->display_identifier)->toBe('CAT-123');
});

test('display_identifier falls back to object_reference_number when catalogue_identifier is null', function () {
    $doc = new Document([
        'catalogue_identifier' => null,
        'object_reference_number' => 'OBJ-001',
        'identifier' => 'R45',
    ]);

    expect($doc->display_identifier)->toBe('OBJ-001');
});

test('display_identifier falls back to identifier when both catalogue_identifier and object_reference_number are null', function () {
    $doc = new Document([
        'catalogue_identifier' => null,
        'object_reference_number' => null,
        'identifier' => 'R45',
    ]);

    expect($doc->display_identifier)->toBe('R45');
});

test('display_identifier is null when all three identifier sources are null', function () {
    $doc = new Document([
        'catalogue_identifier' => null,
        'object_reference_number' => null,
        'identifier' => null,
    ]);

    expect($doc->display_identifier)->toBeNull();
});
