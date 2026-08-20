<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetHeaders;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Client point #3 — import each RAS box's HISTORICAL barcodes (the legacy
 * "Barcode RAS 1/2/3/4" + "Status 1/2/3/4" columns + "Barcode (IN)") from the
 * document sheet into `box_barcode_history`.
 *
 * Driven through the REAL importer entry point (ImportWizard::guessColumnMap +
 * (new DocumentImporter(...))($data)), the same invocation the streaming job
 * uses. The box_types lookup (RAS / IN_SITU / NRA active) is seeded by the
 * lookup migration under RefreshDatabase.
 */
uses(RefreshDatabase::class);

function bch_admin(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    return [$repo, $u];
}

function bch_batch(int $repoId, string $number = '1'): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => $number, 'repository_id' => $repoId],
    );
}

/**
 * A document import row carrying the FULL header set (both barcode blocks
 * present, empty where unused). Passing every header — exactly as the real
 * client sheet does — makes each importer field Tier-1 exact-match its own
 * header in guessColumnMap and avoids fuzzy-steal artefacts.
 *
 * @param array<string,string|int|null> $over
 * @return array<string,string|int|null>
 */
function bch_row(array $over): array
{
    return array_merge([
        'Series' => 'REG',
        'RAS Batch 1' => '', 'RAS Box 1' => '',
        'RAS Batch 2' => '', 'RAS Box 2' => '',
        'In Situ Box 1' => '', 'In Situ Box 2' => '', 'In Situ Box 3' => '',
        // Block 1 (RAS Box 1's barcode chain).
        'Barcode (IN)' => '',
        'Barcode RAS 1' => '', 'Status 1' => '',
        'Barcode RAS 2' => '', 'Status 2' => '',
        'Barcode RAS 3' => '', 'Status 3' => '',
        'Barcode RAS 4' => '', 'Status 4' => '',
        // Block 2 (RAS Box 2's barcode chain) — deduped duplicate headers.
        'Barcode (IN) (2)' => '',
        'Barcode RAS 2 (2)' => '', 'Status 1 (2)' => '',
        'Barcode RAS 2 (3)' => '', 'Status 2 (2)' => '',
    ], $over);
}

/**
 * Import one row through the real entry point with a guessed column map.
 *
 * @param array<string,string|int|null> $data
 */
function bch_import(array $data, int $userId): void
{
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($imp, $map, []))($data);
}

/**
 * Import one row through the real entry point with an IDENTITY column map
 * (keys are importer FIELD names). Used only where a test must control which
 * columns are MAPPED (e.g. the partial-sheet guard) — a guessed map over the
 * full header set always maps every block.
 *
 * @param array<string,string|int|null> $data
 */
function bch_importFields(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new DocumentImporter($imp, $map, []))($data);
}

function bch_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

function bch_box(string $type, string $number): ?Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_type', $type)->where('box_number', $number)->first();
}

/**
 * The legacy-import history rows for a box, oldest→newest (insertion order).
 *
 * @return Collection<int,BoxBarcodeHistory>
 */
function bch_legacy(int $boxId)
{
    return BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $boxId)
        ->where('source', BoxBarcodeHistory::SOURCE_LEGACY)
        ->orderBy('id')
        ->get();
}

function bch_recorded(int $boxId): int
{
    return BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $boxId)
        ->where('source', BoxBarcodeHistory::SOURCE_RECORDED)
        ->count();
}

/* ═══════════════════════════ BC1–BC8 ═══════════════════════════ */

it('BC1: two PERM OUT past barcodes (spaced spelling) → one undated legacy row; box ends at the last one', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BC1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '111',
        'Barcode (IN)' => '',
        'Barcode RAS 1' => 'AA04969', 'Status 1' => 'PERM OUT',   // WITH the space
        'Barcode RAS 2' => 'AA88578', 'Status 2' => 'PERM OUT',
    ]), $u->id);

    $box = bch_box('RAS', '111');
    expect($box)->not->toBeNull();

    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('AA04969')
        ->and($rows->first()->previous_status)->toBe('PERM_OUT')
        ->and($rows->first()->new_barcode)->toBe('AA88578')
        ->and($rows->first()->new_status)->toBe('PERM_OUT')
        ->and($rows->first()->changed_at)->toBeNull()
        ->and($rows->first()->source)->toBe('legacy_import');

    // Box current state = the last past barcode (no IN barcode).
    expect($box->barcode)->toBe('AA88578')
        ->and($box->barcode_status)->toBe('PERM_OUT');
});

it('BC2: a past PERM OUT barcode then a current IN barcode → one row (PERM_OUT → IN); box IN, no C2.2 throw', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BC2',
        'RAS Batch 1' => '1', 'RAS Box 1' => '110',
        'Barcode RAS 1' => 'AA05760', 'Status 1' => 'PERM OUT',
        'Barcode (IN)' => 'AA88692',
    ]), $u->id);

    // The row succeeded (the C2.2 IN-re-entry guard did NOT fire — a fresh box
    // created straight at IN is not a PERM_OUT→IN re-entry).
    $doc = bch_doc('BC2');
    expect($doc)->not->toBeNull();

    $box = bch_box('RAS', '110');
    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('AA05760')
        ->and($rows->first()->previous_status)->toBe('PERM_OUT')
        ->and($rows->first()->new_barcode)->toBe('AA88692')
        ->and($rows->first()->new_status)->toBe('IN');

    expect($box->barcode)->toBe('AA88692')
        ->and($box->barcode_status)->toBe('IN');
});

it('BC3: two-box row — block 1 chains box A, block 2 (IN only) sets box B to IN with 0 rows', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BC3',
        'RAS Batch 1' => '1', 'RAS Box 1' => '301',
        'Barcode RAS 1' => 'A1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'A2',
        'RAS Batch 2' => '2', 'RAS Box 2' => '302',
        'Barcode (IN) (2)' => 'B9',              // block 2: IN barcode only
    ]), $u->id);

    // guessColumnMap mapped the deduped duplicate header to barcode_in_2.
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys(bch_row([])));
    expect($map['barcode_in_2'])->toBe('Barcode (IN) (2)');

    $boxA = bch_box('RAS', '301');
    $boxB = bch_box('RAS', '302');

    // Box A: chain A1(OUT) → A2(IN) = one row.
    expect(bch_legacy($boxA->id))->toHaveCount(1);
    expect($boxA->barcode)->toBe('A2')->and($boxA->barcode_status)->toBe('IN');

    // Box B: only an IN barcode → chain length 1 → zero rows, but state set to IN.
    expect(bch_legacy($boxB->id))->toHaveCount(0);
    expect($boxB->barcode)->toBe('B9')->and($boxB->barcode_status)->toBe('IN');
});

it('BC4: an In-Situ current box with barcode cells present → 0 history rows, box barcode untouched', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    // Pre-create the In-Situ box the row's current box resolves to (by barcode),
    // WITH its own barcode so we can prove the import leaves it untouched.
    $insitu = Box::factory()->create([
        'box_type' => 'IN_SITU', 'box_number' => '404',
        'batch_id' => null, 'repository_id' => $repo->id,
        'provenance_unknown' => true, 'barcode' => 'INSITU-OWN', 'barcode_status' => 'OUT',
    ]);

    bch_import(bch_row([
        'Identifier' => 'BC4',
        'Current box barcode' => 'INSITU-OWN',  // resolves current_box_id to the In-Situ box
        'Barcode RAS 1' => 'ZZ1', 'Status 1' => 'PERM OUT',
        'Barcode (IN)' => 'ZZ2',
    ]), $u->id);

    $doc = bch_doc('BC4');
    expect($doc?->current_box_id)->toBe($insitu->id);

    // In-Situ box is skipped by the RAS-only guards: no history, and its own
    // barcode is NOT overwritten by the RAS-sheet columns.
    expect(bch_legacy($insitu->id))->toHaveCount(0);
    expect($insitu->fresh()->barcode)->toBe('INSITU-OWN');
});

it('BC5: three documents in the same box + same block → one chain, no piling', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    $row = fn (string $id) => bch_row([
        'Identifier' => $id,
        'RAS Batch 1' => '1', 'RAS Box 1' => '505',
        'Barcode RAS 1' => 'M1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'M2',
    ]);

    bch_import($row('BC5a'), $u->id);
    bch_import($row('BC5b'), $u->id);
    bch_import($row('BC5c'), $u->id);

    $box = bch_box('RAS', '505');
    // Delete-and-rebuild per box → exactly ONE row survives (not three).
    expect(bch_legacy($box->id))->toHaveCount(1);
    expect($box->fresh()->barcode)->toBe('M2');
});

it('BC6: re-import is idempotent and a corrected past barcode replaces the stale chain', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    $row = bch_row([
        'Identifier' => 'BC6',
        'RAS Batch 1' => '1', 'RAS Box 1' => '606',
        'Barcode RAS 1' => 'OLD', 'Status 1' => 'PERM OUT',
        'Barcode (IN)' => 'CUR',
    ]);
    bch_import($row, $u->id);
    bch_import($row, $u->id); // identical re-import

    $box = bch_box('RAS', '606');
    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->previous_barcode)->toBe('OLD');

    // Re-import with the past barcode CORRECTED.
    bch_import(bch_row([
        'Identifier' => 'BC6',
        'RAS Batch 1' => '1', 'RAS Box 1' => '606',
        'Barcode RAS 1' => 'FIXED', 'Status 1' => 'PERM OUT',
        'Barcode (IN)' => 'CUR',
    ]), $u->id);

    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->previous_barcode)->toBe('FIXED'); // stale 'OLD' gone
});

it('BC7: a recorded hook row survives re-import, and a later operator change still writes recorded', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BC7',
        'RAS Batch 1' => '1', 'RAS Box 1' => '707',
        'Barcode RAS 1' => 'P1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'P2',
    ]), $u->id);

    $box = bch_box('RAS', '707');

    // Pre-seed a real 'recorded' row directly.
    BoxBarcodeHistory::withoutGlobalScopes()->create([
        'box_id' => $box->id,
        'previous_barcode' => 'HOOK-A',
        'new_barcode' => 'HOOK-B',
        'previous_status' => 'IN',
        'new_status' => 'IN',
        'changed_at' => now(),
        'source' => BoxBarcodeHistory::SOURCE_RECORDED,
        'repository_id' => $repo->id,
    ]);

    // Re-import → legacy rebuilt, recorded row untouched.
    bch_import(bch_row([
        'Identifier' => 'BC7',
        'RAS Batch 1' => '1', 'RAS Box 1' => '707',
        'Barcode RAS 1' => 'P1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'P2',
    ]), $u->id);

    expect(bch_recorded($box->id))->toBe(1)
        ->and(bch_legacy($box->id))->toHaveCount(1);

    // A post-import operator barcode change writes a NEW 'recorded' row (the
    // suppress flag did not leak onto a fresh instance).
    $fresh = Box::withoutGlobalScopes()->find($box->id);
    $fresh->update(['barcode' => 'OP-NEW']);
    expect(bch_recorded($box->id))->toBe(2);
});

it('BC8: the import\'s own box save creates ZERO recorded history rows', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BC8',
        'RAS Batch 1' => '1', 'RAS Box 1' => '808',
        'Barcode RAS 1' => 'Q1', 'Status 1' => 'PERM OUT',
        'Barcode (IN)' => 'Q2',
    ]), $u->id);

    $box = bch_box('RAS', '808');
    expect(bch_recorded($box->id))->toBe(0);           // no spurious hook row
    expect(bch_legacy($box->id))->toHaveCount(1);      // only the legacy chain
});

/* ═══════════════════════════ Status normalisation THROUGH a real import ═══════════════════════════ */

it('normalises a status variant through a real import onto the box + history', function (string $raw, ?string $expected) {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'NORM',
        'RAS Batch 1' => '1', 'RAS Box 1' => '700',
        'Barcode RAS 1' => 'PAST', 'Status 1' => $raw,
        'Barcode RAS 2' => 'CUR',                       // a 2nd barcode so a history row exists
    ]), $u->id);

    $box = bch_box('RAS', '700');
    expect($box)->not->toBeNull();                       // the row always succeeds

    $row = bch_legacy($box->id)->first();
    expect($row)->not->toBeNull();
    // The PAST barcode's status is the normalised variant (or null for garbage).
    expect($row->previous_status)->toBe($expected);
})->with([
    'spaced PERM OUT' => ['PERM OUT', 'PERM_OUT'],
    'lowercase perm out' => ['perm out', 'PERM_OUT'],
    'title-case Perm Out' => ['Perm Out', 'PERM_OUT'],
    'hyphen PERM-OUT' => ['PERM-OUT', 'PERM_OUT'],
    'no-separator PERMOUT' => ['PERMOUT', 'PERM_OUT'],
    'underscore PERM_OUT' => ['PERM_OUT', 'PERM_OUT'],
    'surrounding whitespace' => ['  PERM OUT  ', 'PERM_OUT'],
    'IN' => ['IN', 'IN'],
    'lowercase in' => ['in', 'IN'],
    'OUT' => ['OUT', 'OUT'],
    'garbage XYZ → null' => ['XYZ', null],
]);

/* ═══════════════════════════ Chain / transition modelling ═══════════════════════════ */

it('models a full block-2 chain oldest→newest with IN last (2 rows)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'B2CHAIN',
        'RAS Batch 1' => '1', 'RAS Box 1' => '900',
        'RAS Batch 2' => '2', 'RAS Box 2' => '901',
        'Barcode RAS 2 (2)' => 'C1', 'Status 1 (2)' => 'PERM OUT',
        'Barcode RAS 2 (3)' => 'C2', 'Status 2 (2)' => 'PERM OUT',
        'Barcode (IN) (2)' => 'C3',
    ]), $u->id);

    $boxB = bch_box('RAS', '901');
    $rows = bch_legacy($boxB->id);
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('previous_barcode')->all())->toBe(['C1', 'C2'])
        ->and($rows->pluck('new_barcode')->all())->toBe(['C2', 'C3'])
        ->and($rows->last()->new_status)->toBe('IN');
    expect($boxB->barcode)->toBe('C3')->and($boxB->barcode_status)->toBe('IN');
});

it('compacts a chain with a gap: RAS 1 blank but RAS 2 filled → RAS 2 is the first element', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'GAP',
        'RAS Batch 1' => '1', 'RAS Box 1' => '910',
        'Barcode RAS 1' => '', 'Status 1' => '',   // gap
        'Barcode RAS 2' => 'G2', 'Status 2' => 'OUT',
        'Barcode (IN)' => 'G3',
    ]), $u->id);

    $box = bch_box('RAS', '910');
    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('G2')   // RAS 2 became first
        ->and($rows->first()->previous_status)->toBe('OUT')
        ->and($rows->first()->new_barcode)->toBe('G3');
});

it('a barcode WITHOUT a status carries a null status in its transition slot', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'NOSTAT',
        'RAS Batch 1' => '1', 'RAS Box 1' => '920',
        'Barcode RAS 1' => 'N1',        // no Status 1
        'Barcode (IN)' => 'N2',
    ]), $u->id);

    $box = bch_box('RAS', '920');
    $rows = bch_legacy($box->id);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('N1')
        ->and($rows->first()->previous_status)->toBeNull()
        ->and($rows->first()->new_status)->toBe('IN');
});

it('a status WITHOUT a barcode is ignored entirely (no chain entry)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'STATONLY',
        'RAS Batch 1' => '1', 'RAS Box 1' => '930',
        'Barcode RAS 1' => '', 'Status 1' => 'OUT',   // status, no barcode → ignored
        'Barcode (IN)' => 'S2',
    ]), $u->id);

    $box = bch_box('RAS', '930');
    // Chain = [S2/IN] only → length 1 → zero rows; box set to the IN barcode.
    expect(bch_legacy($box->id))->toHaveCount(0);
    expect($box->barcode)->toBe('S2')->and($box->barcode_status)->toBe('IN');
});

it('only a current IN barcode, no past barcodes → 0 rows but box set to IN', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'INONLY',
        'RAS Batch 1' => '1', 'RAS Box 1' => '940',
        'Barcode (IN)' => 'I9',
    ]), $u->id);

    $box = bch_box('RAS', '940');
    expect(bch_legacy($box->id))->toHaveCount(0);
    expect($box->barcode)->toBe('I9')->and($box->barcode_status)->toBe('IN');
});

it('only past PERM OUT barcodes, no IN → box PERM_OUT at the last past barcode; n-1 rows', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'NOIN',
        'RAS Batch 1' => '1', 'RAS Box 1' => '950',
        'Barcode RAS 1' => 'K1', 'Status 1' => 'PERM OUT',
        'Barcode RAS 2' => 'K2', 'Status 2' => 'PERM OUT',
        'Barcode RAS 3' => 'K3', 'Status 3' => 'PERM OUT',
        // no Barcode (IN)
    ]), $u->id);

    $box = bch_box('RAS', '950');
    expect(bch_legacy($box->id))->toHaveCount(2); // 3 barcodes → 2 rows
    expect($box->barcode)->toBe('K3')->and($box->barcode_status)->toBe('PERM_OUT');
});

it('a single past barcode with no IN (chain length 1) → 0 rows, box barcode = that barcode', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'ONE',
        'RAS Batch 1' => '1', 'RAS Box 1' => '960',
        'Barcode RAS 1' => 'ONLY1', 'Status 1' => 'OUT',
    ]), $u->id);

    $box = bch_box('RAS', '960');
    expect(bch_legacy($box->id))->toHaveCount(0);
    expect($box->barcode)->toBe('ONLY1')->and($box->barcode_status)->toBe('OUT');
});

/* ═══════════════════════════ Box state (the bug the collapse hid) ═══════════════════════════ */

it('a box currently IN keeps status IN even with PERM_OUT past barcodes (the old collapse bug)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BUGIN',
        'RAS Batch 1' => '1', 'RAS Box 1' => '970',
        'Barcode RAS 1' => 'OLD1', 'Status 1' => 'PERM OUT',
        'Barcode RAS 2' => 'OLD2', 'Status 2' => 'PERM OUT',
        'Barcode (IN)' => 'NEW9',
    ]), $u->id);

    $box = bch_box('RAS', '970');
    // The old "PERM_OUT wins" collapse would have set PERM_OUT — the terminal
    // state is IN because the box currently holds the IN barcode.
    expect($box->barcode_status)->toBe('IN')
        ->and($box->barcode)->toBe('NEW9');
    expect(bch_legacy($box->id))->toHaveCount(2);
});

it('the importer writes the box barcode itself (not only the status)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'BCSET',
        'RAS Batch 1' => '1', 'RAS Box 1' => '975',
        'Barcode (IN)' => 'WRITTEN',
    ]), $u->id);

    expect(bch_box('RAS', '975')->barcode)->toBe('WRITTEN');
});

it('the box state mirrors onto the document in the box (Task-7 mirror on the real save)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'MIRROR',
        'RAS Batch 1' => '1', 'RAS Box 1' => '980',
        'Barcode RAS 1' => 'D1', 'Status 1' => 'PERM OUT',
        // no IN barcode → box ends PERM_OUT; current_box_id stays this box.
    ]), $u->id);

    $doc = bch_doc('MIRROR');
    expect($doc->current_box_id)->toBe(bch_box('RAS', '980')->id)
        ->and($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

/* ═══════════════════════════ Idempotency / dedup / coexistence ═══════════════════════════ */

it('a partial sheet that maps NO block-2 column does not wipe block-2 legacy history', function () {
    [$repo, $u] = bch_admin();
    $batch = bch_batch($repo->id, '1');

    // Pre-create RAS Box 2 and seed a legacy-import row on it directly.
    $boxB = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'box_number' => '991']);
    BoxBarcodeHistory::withoutGlobalScopes()->create([
        'box_id' => $boxB->id,
        'previous_barcode' => 'KEEP1', 'new_barcode' => 'KEEP2',
        'previous_status' => 'PERM_OUT', 'new_status' => 'IN',
        'changed_at' => null, 'source' => BoxBarcodeHistory::SOURCE_LEGACY,
        'repository_id' => $repo->id,
    ]);

    // Import a row (identity map) that maps ONLY block-1 columns — block 2 is
    // entirely unmapped, so its history must be left alone.
    bch_importFields([
        'identifier' => 'PARTIAL',
        'series' => 'REG',
        'batch_number' => '1',
        'current_box_number' => '990',
        'barcode_ras_1' => 'X1',
        'status_1' => 'PERM OUT',
        'barcode_in' => 'X2',
    ], $u->id);

    // Block 2's pre-existing legacy history is intact.
    expect(bch_legacy($boxB->id))->toHaveCount(1)
        ->and(bch_legacy($boxB->id)->first()->previous_barcode)->toBe('KEEP1');
});

it('two rows sharing a box with conflicting blocks → last import wins (delete-and-rebuild)', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'CONFA',
        'RAS Batch 1' => '1', 'RAS Box 1' => '995',
        'Barcode RAS 1' => 'V1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'V2',
    ]), $u->id);

    // A second document lands in the SAME box with a different chain.
    bch_import(bch_row([
        'Identifier' => 'CONFB',
        'RAS Batch 1' => '1', 'RAS Box 1' => '995',
        'Barcode RAS 1' => 'W1', 'Status 1' => 'PERM OUT',
        'Barcode RAS 2' => 'W2', 'Status 2' => 'PERM OUT',
        'Barcode (IN)' => 'W3',
    ]), $u->id);

    $box = bch_box('RAS', '995');
    $rows = bch_legacy($box->id);
    // Final chain is the SECOND row's (2 rows), not the first's (1 row).
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('previous_barcode')->all())->toBe(['W1', 'W2']);
    expect($box->barcode)->toBe('W3');
});

it('barcodeHistory() lists dated recorded rows BEFORE undated legacy rows', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'ORDER',
        'RAS Batch 1' => '1', 'RAS Box 1' => '997',
        'Barcode RAS 1' => 'L1', 'Status 1' => 'OUT',
        'Barcode (IN)' => 'L2',
    ]), $u->id);

    $box = Box::withoutGlobalScopes()->find(bch_box('RAS', '997')->id);
    // A real dated recorded change.
    $box->update(['barcode' => 'L3']);

    $ordered = $box->barcodeHistory()->get();
    // First row is the dated recorded one; the undated legacy row sinks last.
    expect($ordered->first()->source)->toBe('recorded')
        ->and($ordered->first()->changed_at)->not->toBeNull()
        ->and($ordered->last()->source)->toBe('legacy_import')
        ->and($ordered->last()->changed_at)->toBeNull();
});

/* ═══════════════════════════ Template / mapping round-trip ═══════════════════════════ */

it('the document template round-trips: every barcode/status column maps to its own deduped header', function () {
    $headers = SpreadsheetHeaders::dedupe(TemplateGenerator::headersFor('document'));
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    $expected = [
        'barcode_in' => 'Barcode (IN)',
        'barcode_ras_1' => 'Barcode RAS 1',
        'status_1' => 'Status 1',
        'barcode_ras_2' => 'Barcode RAS 2',
        'status_2' => 'Status 2',
        'barcode_ras_3' => 'Barcode RAS 3',
        'status_3' => 'Status 3',
        'barcode_ras_4' => 'Barcode RAS 4',
        'status_4' => 'Status 4',
        'barcode_in_2' => 'Barcode (IN) (2)',
        'barcode_ras_2_alt' => 'Barcode RAS 2 (2)',
        'status_1_alt' => 'Status 1 (2)',
        'barcode_ras_2_alt2' => 'Barcode RAS 2 (3)',
        'status_2_alt' => 'Status 2 (2)',
    ];

    foreach ($expected as $field => $header) {
        expect($map[$field] ?? null)->toBe($header, "field {$field} should map to {$header}");
    }

    // No two of these columns claim the same deduped header.
    $claimed = array_values(array_intersect_key($map, $expected));
    expect(count($claimed))->toBe(count(array_unique($claimed)));
});

/* ═══════════════════════════ Edge — global-unique barcode collision ═══════════════════════════ */

it('a second box given a duplicate IN barcode fails safely; the first box is intact', function () {
    [$repo, $u] = bch_admin();
    bch_batch($repo->id, '1');

    bch_import(bch_row([
        'Identifier' => 'DUPA',
        'RAS Batch 1' => '1', 'RAS Box 1' => '801',
        'Barcode (IN)' => 'DUP123',
    ]), $u->id);

    expect(bch_box('RAS', '801')->barcode)->toBe('DUP123');

    // Second row: a DIFFERENT box, same global-unique barcode → the box save
    // throws, the per-row savepoint rolls back, the document never persists.
    $threw = false;

    try {
        bch_import(bch_row([
            'Identifier' => 'DUPB',
            'RAS Batch 1' => '1', 'RAS Box 1' => '802',
            'Barcode (IN)' => 'DUP123',
        ]), $u->id);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(bch_doc('DUPB'))->toBeNull();                      // rolled back
    expect(bch_box('RAS', '801')->fresh()->barcode)->toBe('DUP123'); // first box intact
});
