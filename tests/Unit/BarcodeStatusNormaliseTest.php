<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;

/**
 * Client point #3 (bug fix) — the shared status cast used by every barcode
 * status ImportColumn. The key regression it closes: Charlene writes "PERM OUT"
 * (with a space), which the old exact-token check silently dropped to null.
 *
 * normaliseBarcodeStatus() is a private static helper (the column casts are
 * static closures that call it via self::), so it is exercised here by
 * reflection.
 */
function normaliseStatus(?string $raw): ?string
{
    $m = new ReflectionMethod(DocumentImporter::class, 'normaliseBarcodeStatus');
    $m->setAccessible(true);

    return $m->invoke(null, $raw);
}

it('normalises a raw status cell to the canonical token', function (?string $raw, ?string $expected) {
    expect(normaliseStatus($raw))->toBe($expected);
})->with([
    'spaced PERM OUT' => ['PERM OUT', 'PERM_OUT'],
    'lowercase perm out' => ['perm out', 'PERM_OUT'],
    'title-case Perm Out' => ['Perm Out', 'PERM_OUT'],
    'hyphen PERM-OUT' => ['PERM-OUT', 'PERM_OUT'],
    'no-separator PERMOUT' => ['PERMOUT', 'PERM_OUT'],
    'canonical PERM_OUT' => ['PERM_OUT', 'PERM_OUT'],
    'surrounding whitespace' => ['   PERM OUT   ', 'PERM_OUT'],
    'IN' => ['IN', 'IN'],
    'lowercase in' => ['in', 'IN'],
    'padded OUT' => ['  out  ', 'OUT'],
    'garbage XYZ → null' => ['XYZ', null],
    'blank → null' => ['', null],
    'null → null' => [null, null],
]);
