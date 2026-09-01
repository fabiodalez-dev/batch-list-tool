<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Support\BulkImport\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Schema audit (#14): the AppServiceProvider registers a Unicode-aware LOWER()
 * on SQLite connections so the test/dev driver folds accented Latin like the
 * MySQL/MariaDB prod database (utf8mb4). Without it, SQLite's ASCII-only LOWER()
 * left accented uppercase unchanged and the import resolvers under-matched in
 * tests relative to prod.
 */
uses(RefreshDatabase::class);

it('folds accented uppercase in SQLite LOWER() (matches the utf8mb4 prod behaviour)', function () {
    // Guard: this test is about the SQLite driver fidelity fix.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific driver-fidelity test.');
    }

    // Direct proof: a raw LOWER() over an accented uppercase value now folds the
    // accents (ASCII LOWER would leave Ñ untouched → no match).
    $lowered = DB::selectOne("SELECT LOWER('ÑOÑO') AS v")->v;
    expect($lowered)->toBe('ñoño');
});

it('resolves an authority whose surname is stored accented-uppercase via a lowercase needle', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific driver-fidelity test.');
    }

    Authority::query()->create([
        'identifier' => 'R-ACC-1',
        'surname' => 'MUÑOZ',
        'entity_type' => 'Notary',
    ]);
    EntityResolver::flushMemo();

    // The importer lowercases the needle and compares LOWER(surname) — with the
    // Unicode LOWER() the accented 'MUÑOZ' folds to 'muñoz' and resolves.
    $rows = Authority::query()
        ->whereRaw('LOWER(surname) = ?', [mb_strtolower('Muñoz')])
        ->pluck('id');

    expect($rows)->toHaveCount(1);
});
