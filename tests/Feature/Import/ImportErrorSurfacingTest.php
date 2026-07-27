<?php

declare(strict_types=1);

use App\Filament\Imports\SeriesImporter;

/**
 * Client-facing requirement (2026-07-27): when an import row fails, the operator
 * must see the REAL reason, not the opaque "generic_validation" the streaming
 * importer shows. LogsImportRows::humaniseImportError() turns a raw DB/other
 * exception into a short, clear message (no "SQLSTATE", < 200 chars) so it
 * survives the vendor's masking and lands in the failed-rows report.
 */
it('humanises a duplicate-key error into a clear operator message', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'R' for key 'series_code_unique' (Connection: mysql, SQL: insert into `series` ...)");
    $msg = SeriesImporter::humaniseImportError($e);

    expect($msg)->toContain('already exists')
        ->and($msg)->toContain('R')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

it('humanises a NOT NULL error into a missing-value message', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'title' cannot be null");
    $msg = SeriesImporter::humaniseImportError($e);

    expect($msg)->toContain("'title'")
        ->and($msg)->toContain('required')
        ->and($msg)->not->toContain('SQLSTATE');
});

it('humanises a data-too-long error', function () {
    $e = new Exception("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'code' at row 1");
    $msg = SeriesImporter::humaniseImportError($e);

    expect($msg)->toContain("'code'")
        ->and($msg)->toContain('too long')
        ->and($msg)->not->toContain('SQLSTATE');
});

it('humanises a foreign-key error', function () {
    $e = new Exception('SQLSTATE[23000]: ... a foreign key constraint fails ...');
    $msg = SeriesImporter::humaniseImportError($e);

    expect($msg)->toContain('references')
        ->and($msg)->not->toContain('SQLSTATE');
});

it('passes a short non-DB message through unchanged and clamps a long one', function () {
    expect(SeriesImporter::humaniseImportError(new Exception('Practice code must already exist.')))
        ->toBe('Practice code must already exist.');

    $long = str_repeat('x', 400);
    $clamped = SeriesImporter::humaniseImportError(new Exception($long));
    expect(mb_strlen($clamped))->toBeLessThanOrEqual(180)
        ->and($clamped)->toEndWith('…');
});
