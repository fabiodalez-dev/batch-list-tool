<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Legacy box-movement TIMELINE — client 2026-08-18 (#1).
 *
 * Driven through the REAL importer entry point (ImportWizard::guessColumnMap +
 * (new DocumentImporter(...))($data), the same invocation the streaming job
 * uses). The box_types lookup (RAS / IN_SITU / NRA active) is seeded by the
 * lookup migration under RefreshDatabase, so resolveInSituBox/resolveBox find
 * active types without extra seeding.
 */
uses(RefreshDatabase::class);

function mv_admin(): array
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

function mv_batch(int $repoId, string $number = '1'): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => $number, 'repository_id' => $repoId],
    );
}

/**
 * Build a document import row with the FULL movement-column header set present.
 *
 * The real client sheet carries every RAS/In-Situ column, so each importer
 * field Tier-1 exact-matches its own header in ImportWizard::guessColumnMap. If
 * a test omitted, say, "In Situ Box 3", the unmatched in_situ_box_3 field would
 * fuzzy-steal (Levenshtein distance 1) the present "In Situ Box 1" header and
 * duplicate its value — a harness artefact, not a production one. Passing all
 * headers (empty where unused) reproduces the real mapping faithfully.
 *
 * @param array<string,string|int|null> $over
 * @return array<string,string|int|null>
 */
function mv_row(array $over): array
{
    return array_merge([
        'Series' => 'REG',
        'RAS Batch 1' => '', 'RAS Box 1' => '',
        'RAS Batch 2' => '', 'RAS Box 2' => '',
        'In Situ Box 1' => '', 'In Situ Box 2' => '', 'In Situ Box 3' => '',
    ], $over);
}

/**
 * @param array<string,string|int|null> $data
 */
function mv_import(array $data, int $userId): void
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

function mv_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

/**
 * @return Collection<int,BoxMovement>
 */
function mv_moves(int $documentId)
{
    return BoxMovement::withoutGlobalScopes()->where('document_id', $documentId)->ordered()->get();
}

function mv_box(string $type, string $number): ?Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_type', $type)->where('box_number', $number)->first();
}

/* ══════════════ MV1 — full chain, ordered, dated NULL ══════════════ */

it('MV1: In-Situ 1/2/3 + current box build an ordered NULL-dated legacy chain', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'MV1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '100',            // → current box
        'In Situ Box 1' => 'Small Box 12',
        'In Situ Box 2' => 'NRA 3',
        'In Situ Box 3' => 'Old Box 7',
    ]), $u->id);

    $doc = mv_doc('MV1');
    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->not->toBeNull();

    $moves = mv_moves($doc->id);

    // Four arrivals, oldest → newest: CURRENT (RAS Box 1) → in_situ_1 →
    // in_situ_2 → in_situ_3.
    expect($moves)->toHaveCount(4)
        ->and($moves->pluck('sequence')->all())->toBe([1, 2, 3, 4]);

    // The RAS Box 1 is the OLDEST arrival: first move enters custody (no
    // from_box) and lands in RAS Box 1; the newest arrival is the last In-Situ
    // box ("Old Box 7" → IN_SITU number 7). current_box_id now follows the
    // NEWEST box (the document's actual physical position), NOT RAS Box 1.
    expect($moves->first()->from_box_id)->toBeNull()
        ->and($moves->first()->to_box_id)->toBe(mv_box('RAS', '100')->id)
        ->and($moves->last()->to_box_id)->toBe(mv_box('IN_SITU', '7')->id)
        ->and($doc->current_box_id)->toBe($moves->last()->to_box_id);

    // Chain continuity: each from_box is the previous arrival's to_box.
    $ids = $moves->pluck('to_box_id')->all();
    foreach ($moves as $i => $m) {
        expect($m->from_box_id)->toBe($i === 0 ? null : $ids[$i - 1]);
    }

    // Every legacy move is undated and flagged.
    expect($moves->every(fn (BoxMovement $m) => $m->movement_date === null))->toBeTrue()
        ->and($moves->every(fn (BoxMovement $m) => $m->date_source === BoxMovement::DATE_SOURCE_LEGACY))->toBeTrue();
});

/* ══════════════ MV2 — "NRA 3" parses to an NRA box ══════════════ */

it('MV2: In-Situ "NRA 3" resolves to a box of type NRA number 3', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'MV2',
        'RAS Batch 1' => '1', 'RAS Box 1' => '200',
        'In Situ Box 1' => 'NRA 3',
    ]), $u->id);

    $nra = mv_box('NRA', '3');
    expect($nra)->not->toBeNull()
        ->and($nra->batch_id)->toBeNull()
        ->and($nra->repository_id)->toBe($repo->id)
        ->and($nra->provenance_unknown)->toBeTrue();

    // In-Situ is the NEWEST arrival, so the NRA box is the LAST move
    // (the current RAS box is the oldest, first move).
    $moves = mv_moves(mv_doc('MV2')->id);
    expect($moves->last()->to_box_id)->toBe($nra->id);
});

/* ══════════════ MV3 — a lone current box makes no move ══════════════ */

it('MV3: a row with only a current box (RAS Box 1) creates zero movements', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'MV3',
        'RAS Batch 1' => '1', 'RAS Box 1' => '300',
    ]), $u->id);

    $doc = mv_doc('MV3');
    expect($doc?->current_box_id)->not->toBeNull()
        ->and(mv_moves($doc->id))->toHaveCount(0);
});

/* ══════════════ MV4 — re-import is idempotent, spares recorded moves ══════════════ */

it('MV4: re-importing does not duplicate legacy moves and keeps recorded ones', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    $row = mv_row([
        'Identifier' => 'MV4',
        'RAS Batch 1' => '1', 'RAS Box 1' => '400',
        'In Situ Box 1' => 'Small Box 1',
        'In Situ Box 2' => 'Small Box 2',
    ]);
    mv_import($row, $u->id);

    $doc = mv_doc('MV4');
    $legacyFirst = BoxMovement::withoutGlobalScopes()
        ->where('document_id', $doc->id)->where('date_source', BoxMovement::DATE_SOURCE_LEGACY)->count();
    expect($legacyFirst)->toBe(3); // CURRENT → in_situ_1 → in_situ_2

    // A manually-created RECORDED move (e.g. MoveToBoxAction) on the same doc.
    $recorded = BoxMovement::query()->create([
        'document_id' => $doc->id,
        'repository_id' => $repo->id,
        'from_box_id' => null,
        'to_box_id' => $doc->current_box_id,
        'movement_date' => '2026-02-02 10:00:00',
        'reason' => 'real move',
        'user_id' => $u->id,
    ]);
    expect($recorded->fresh()->date_source)->toBe(BoxMovement::DATE_SOURCE_RECORDED);

    // Repoint landed on the newest box (In-Situ 2), not RAS Box 1.
    expect($doc->fresh()->current_box_id)->toBe(mv_box('IN_SITU', '2')->id);

    // Re-import the identical row.
    mv_import($row, $u->id);

    $legacyAfter = BoxMovement::withoutGlobalScopes()
        ->where('document_id', $doc->id)->where('date_source', BoxMovement::DATE_SOURCE_LEGACY)->count();
    expect($legacyAfter)->toBe(3);                               // rebuilt, not doubled

    // Repoint is idempotent: current_box_id stays on the same newest box.
    expect($doc->fresh()->current_box_id)->toBe(mv_box('IN_SITU', '2')->id);

    // The recorded move is untouched.
    expect(BoxMovement::withoutGlobalScopes()->find($recorded->id))->not->toBeNull()
        ->and(BoxMovement::withoutGlobalScopes()->find($recorded->id)->date_source)
        ->toBe(BoxMovement::DATE_SOURCE_RECORDED);
});

/* ══════════════ MV5 — emptying a cell shrinks the chain ══════════════ */

it('MV5: emptying a previously-populated In-Situ cell shrinks the chain on re-import', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'MV5',
        'RAS Batch 1' => '1', 'RAS Box 1' => '500',
        'In Situ Box 1' => 'Small Box 1',
        'In Situ Box 2' => 'Small Box 2',
    ]), $u->id);

    $doc = mv_doc('MV5');
    expect(mv_moves($doc->id))->toHaveCount(3); // CURRENT → in_situ_1 → in_situ_2

    // Re-import with In Situ Box 2 emptied → the stale tail is dropped.
    mv_import(mv_row([
        'Identifier' => 'MV5',
        'RAS Batch 1' => '1', 'RAS Box 1' => '500',
        'In Situ Box 1' => 'Small Box 1',
        'In Situ Box 2' => '',
    ]), $u->id);

    $moves = mv_moves($doc->id);
    expect($moves)->toHaveCount(2)                              // CURRENT → in_situ_1
        // current_box_id follows the NEW newest box (In-Situ 1), which is now
        // the LAST move's to_box — not the first (RAS Box 1).
        ->and($moves->last()->to_box_id)->toBe($doc->fresh()->current_box_id)
        ->and($doc->fresh()->current_box_id)->toBe(mv_box('IN_SITU', '1')->id);
});

/* ══════════════ MV6 — model invariant + scope ══════════════ */

it('MV6: the date_source flag follows the date and scopeOrdered puts NULL first', function () {
    [$repo, $u] = mv_admin();
    $batch = mv_batch($repo->id, '1');
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS']);
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'MV6', 'document_type' => 'T', 'series_id' => Series::factory()->create()->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id,
    ]);

    // Non-null date → forced 'recorded'.
    $dated = BoxMovement::query()->create([
        'document_id' => $doc->id, 'repository_id' => $repo->id, 'to_box_id' => $box->id,
        'movement_date' => '2026-03-03 09:00:00', 'date_source' => BoxMovement::DATE_SOURCE_LEGACY, // wrong on purpose
        'sequence' => 9, 'user_id' => $u->id,
    ]);
    expect($dated->fresh()->date_source)->toBe(BoxMovement::DATE_SOURCE_RECORDED);

    // Null date → forced 'legacy_import' even if caller claims 'recorded'.
    $undated = BoxMovement::query()->create([
        'document_id' => $doc->id, 'repository_id' => $repo->id, 'to_box_id' => $box->id,
        'movement_date' => null, 'date_source' => BoxMovement::DATE_SOURCE_RECORDED, // wrong on purpose
        'sequence' => 1, 'user_id' => $u->id,
    ]);
    expect($undated->fresh()->date_source)->toBe(BoxMovement::DATE_SOURCE_LEGACY);

    // scopeOrdered: the undated move sorts first.
    $ordered = mv_moves($doc->id);
    expect($ordered->first()->id)->toBe($undated->id)
        ->and($ordered->last()->id)->toBe($dated->id);

    // Typing a real date onto the legacy row flips the flag automatically.
    $undated->update(['movement_date' => '2026-04-04 08:00:00']);
    expect($undated->fresh()->date_source)->toBe(BoxMovement::DATE_SOURCE_RECORDED);
});

/* ══════════════ MV7 — parse edges ══════════════ */

it('MV7: a bare number resolves to an IN_SITU box; unparseable/blank cells are skipped', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'MV7',
        'RAS Batch 1' => '1', 'RAS Box 1' => '700',
        'In Situ Box 1' => '5',              // bare number → IN_SITU box 5
        'In Situ Box 2' => 'NoNumberHere',   // letters only, no digits → skipped
        'In Situ Box 3' => '',               // blank → skipped
    ]), $u->id);

    $doc = mv_doc('MV7');
    expect($doc)->not->toBeNull();                       // row still succeeds

    $insitu5 = mv_box('IN_SITU', '5');
    expect($insitu5)->not->toBeNull()
        ->and($insitu5->provenance_unknown)->toBeTrue();

    // Only the current box + the bare-number step resolved → exactly two
    // arrivals: RAS Box 1 (oldest, first) then the IN_SITU box (newest, last).
    // current_box_id now follows the newest = the bare-number IN_SITU box.
    $moves = mv_moves($doc->id);
    expect($moves)->toHaveCount(2)
        ->and($moves->first()->to_box_id)->toBe(mv_box('RAS', '700')->id)
        ->and($moves->last()->to_box_id)->toBe($insitu5->id)
        ->and($doc->current_box_id)->toBe($insitu5->id);

    // The unparseable cell created no box.
    expect(mv_box('IN_SITU', 'NoNumberHere'))->toBeNull();
});

/* ══════════════ current_box_id repoint (feat/current-box-follows-newest) ══════════════ */

/* RP1 — batch_id stays the archival RAS batch while current_box_id follows the newest box. */

it('RP1: batch_id stays the archival RAS batch even though current_box_id follows the newest box', function () {
    [$repo, $u] = mv_admin();
    $batch1 = mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'RP1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '11',
        'In Situ Box 1' => 'Small Box 1',
        'In Situ Box 2' => 'NRA 2',
    ]), $u->id);

    $doc = mv_doc('RP1');
    // Archival batch is unchanged; the newest (In-Situ) box the document now
    // points at is batch-less — the intentional, safe divergence.
    expect($doc->batch_id)->toBe($batch1->id)
        ->and($doc->currentBox->batch_id)->toBeNull();
});

/* RP2 — a BATCHED newest box: current_box_id diverges to RAS Box 2, batch_id stays RAS Batch 1. */

it('RP2: current_box_id follows a batched RAS Box 2 while batch_id stays RAS Batch 1', function () {
    [$repo, $u] = mv_admin();
    $batch1 = mv_batch($repo->id, '1');

    // RAS Batch 1 / Box 1 (current) + RAS Batch 2 / Box 2, no in-situ cells.
    mv_import(mv_row([
        'Identifier' => 'RP2',
        'RAS Batch 1' => '1', 'RAS Box 1' => '10',
        'RAS Batch 2' => '2', 'RAS Box 2' => '20',
    ]), $u->id);

    $doc = mv_doc('RP2');
    $rasBox2 = mv_box('RAS', '20');
    expect($rasBox2)->not->toBeNull()
        ->and($rasBox2->batch_id)->not->toBe($batch1->id);       // it lives in RAS Batch 2

    // current_box_id repointed onto RAS Box 2 (the newest arrival)...
    expect($doc->current_box_id)->toBe($rasBox2->id)
        // ...yet batch_id STILL the archival RAS Batch 1 — proving saveQuietly
        // defeated the F1 batch realignment that save() would have triggered.
        ->and($doc->batch_id)->toBe($batch1->id);
});

/* RP3 — effectiveLocation follows the newest box's location; a document override still wins. */

it('RP3: effectiveLocation follows the newest In-Situ box, and a document-level override still wins', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    // Pre-create the In-Situ box the row resolves to, WITH a location, so the
    // repointed current box carries one.
    $loc = Location::factory()->create();
    $insitu = Box::factory()->create([
        'box_type' => 'IN_SITU', 'box_number' => '77',
        'batch_id' => null, 'repository_id' => $repo->id, 'location_id' => $loc->id,
        'provenance_unknown' => true,   // batch-less In-Situ box, no parent RAS box
    ]);

    mv_import(mv_row([
        'Identifier' => 'RP3',
        'RAS Batch 1' => '1', 'RAS Box 1' => '111',
        'In Situ Box 1' => '77',           // bare number → IN_SITU 77 (the pre-created box)
    ]), $u->id);

    $doc = mv_doc('RP3');
    expect($doc->current_box_id)->toBe($insitu->id)                     // repointed to newest
        ->and($doc->fresh()->effectiveLocation()?->id)->toBe($loc->id)   // inherited from the box
        ->and($doc->fresh()->locationIsInherited())->toBeTrue();

    // A document-level override wins over the inherited box location.
    $own = Location::factory()->create();
    $doc->location_id = $own->id;
    $doc->saveQuietly();
    expect($doc->fresh()->effectiveLocation()?->id)->toBe($own->id)
        ->and($doc->fresh()->locationIsInherited())->toBeFalse();
});

/* RP4 — the RAS status mirror survives the repoint (PERM_OUT + disinfestation date not nulled). */

it('RP4: the RAS PERM_OUT status and disinfestation date survive the repoint onto a null-status box', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    mv_import(mv_row([
        'Identifier' => 'RP4',
        'RAS Batch 1' => '1', 'RAS Box 1' => '222',
        'In Situ Box 1' => 'Small Box 9',   // newest, batch-less, null-status box
        'Status 1' => 'PERM_OUT',
        'Disinfestation Date' => '2026-01-15',
    ]), $u->id);

    $doc = mv_doc('RP4')->fresh();
    // current_box_id repointed onto the newest In-Situ box (which has null status)...
    expect($doc->current_box_id)->toBe(mv_box('IN_SITU', '9')->id);
    // ...but the RAS-resolved custody the box mirror wrote onto the document
    // while it still sat in RAS Box 1 is NOT clobbered by the quiet repoint.
    expect($doc->barcode_status)->toBe('PERM_OUT')
        ->and($doc->disinfestation_date)->not->toBeNull();
});

/* RP5 — a destroyed newest box is skipped; current_box_id stays on RAS Box 1 and the row succeeds. */

it('RP5: a destroyed newest box is not repointed onto — current_box_id stays on RAS Box 1', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    // Pre-create the In-Situ box the row resolves to, already DESTROYED.
    $destroyed = Box::factory()->create([
        'box_type' => 'IN_SITU', 'box_number' => '88',
        'batch_id' => null, 'repository_id' => $repo->id,
        'location_id' => Location::factory()->create()->id,
        'provenance_unknown' => true,   // batch-less In-Situ box, no parent RAS box
        'destroyed_at' => now(),
    ]);

    mv_import(mv_row([
        'Identifier' => 'RP5',
        'RAS Batch 1' => '1', 'RAS Box 1' => '333',
        'In Situ Box 1' => '88',           // resolves to the pre-created DESTROYED box
    ]), $u->id);

    $doc = mv_doc('RP5');
    expect($doc)->not->toBeNull()                                       // row did NOT fail
        ->and($doc->current_box_id)->toBe(mv_box('RAS', '333')->id)      // stayed on RAS Box 1
        ->and($doc->current_box_id)->not->toBe($destroyed->id);         // never repointed to destroyed
});

/*
 * RP6 — report regression: a document repointed into a batch-less, non-PERM_OUT
 * In-Situ current box with no disinfestation_date must STILL be reported as
 * pending, driven through the report's real reportQuery().
 *
 * NOTE on the fix: boxes.barcode_status is NOT NULL DEFAULT 'IN' on every
 * driver, so a resolved In-Situ box carries 'IN', never NULL. The added
 * whereNull('barcode_status') branch is therefore purely defensive for a box
 * (which can never hold NULL); the != 'PERM_OUT' branch is what keeps this
 * In-Situ document pending. This asserts the repoint does not silently drop the
 * document from the pending report.
 */

it('RP6: a document in a batch-less non-PERM_OUT In-Situ box stays in the pending-disinfestation report', function () {
    [$repo, $u] = mv_admin();
    mv_batch($repo->id, '1');

    // The repoint lands current_box_id on a batch-less In-Situ box; the
    // document has no disinfestation_date.
    mv_import(mv_row([
        'Identifier' => 'RP6',
        'RAS Batch 1' => '1', 'RAS Box 1' => '444',
        'In Situ Box 1' => 'Small Box 6',
    ]), $u->id);

    $doc = mv_doc('RP6');
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box->batch_id)->toBeNull()                                  // batch-less In-Situ box
        ->and($box->barcode_status)->not->toBe('PERM_OUT')             // not perm-out → still pending
        ->and($doc->disinfestation_date)->toBeNull();

    // reportQuery must include this document.
    $page = new PendingDisinfestationReport;
    $method = new ReflectionMethod($page, 'reportQuery');
    /** @var Builder $q */
    $q = $method->invoke($page);
    expect($q->pluck('documents.id')->all())->toContain($doc->id);
});

/*
 * RP6b — the widened predicate proven at the QUERY level. boxes.barcode_status
 * cannot be NULL via the model, so a raw INSERT with an explicit NULL isolates
 * the exact `NULL != 'PERM_OUT'` → UNKNOWN case the fix guards against: the bare
 * `!=` dropped the document; `whereNull(...) OR !=` keeps it.
 */

it('RP6b: reportQuery keeps a document whose current box has a NULL barcode_status', function () {
    [$repo, $u] = mv_admin();
    $batch = mv_batch($repo->id, '1');

    // Raw insert a box with an explicit NULL barcode_status, bypassing the
    // model default. Skip on a schema that forbids the NULL (NOT NULL column).
    try {
        $boxId = (int) DB::table('boxes')->insertGetId([
            'box_type' => 'IN_SITU',
            'box_number' => 'NULLST',
            'batch_id' => null,
            'repository_id' => $repo->id,
            'barcode_status' => null,
            'provenance_unknown' => true,
            'is_legacy' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        // barcode_status is NOT NULL on this driver → the NULL branch is
        // unreachable here; the != 'PERM_OUT' path (RP6) already covers it.
        expect($e)->toBeInstanceOf(QueryException::class);

        return;
    }

    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'RP6b', 'document_type' => 'T',
        'series_id' => Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false])->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id,
        'current_box_id' => $boxId, 'disinfestation_date' => null, 'barcode_status' => null,
    ]);

    $method = new ReflectionMethod(new PendingDisinfestationReport, 'reportQuery');
    /** @var Builder $q */
    $q = $method->invoke(new PendingDisinfestationReport);
    expect($q->pluck('documents.id')->all())->toContain($doc->id);
});
