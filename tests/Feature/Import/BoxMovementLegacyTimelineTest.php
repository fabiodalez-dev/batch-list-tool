<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // The RAS current box is the OLDEST arrival: first move enters custody
    // (no from_box) and lands in the current box; the newest arrival is the
    // last In-Situ box ("Old Box 7" → IN_SITU number 7).
    expect($moves->first()->from_box_id)->toBeNull()
        ->and($moves->first()->to_box_id)->toBe($doc->current_box_id)
        ->and($moves->last()->to_box_id)->toBe(mv_box('IN_SITU', '7')->id);

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

    // Re-import the identical row.
    mv_import($row, $u->id);

    $legacyAfter = BoxMovement::withoutGlobalScopes()
        ->where('document_id', $doc->id)->where('date_source', BoxMovement::DATE_SOURCE_LEGACY)->count();
    expect($legacyAfter)->toBe(3);                               // rebuilt, not doubled

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
        ->and($moves->first()->to_box_id)->toBe($doc->fresh()->current_box_id);
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
    // arrivals: CURRENT (oldest, first) then the IN_SITU box (newest, last).
    $moves = mv_moves($doc->id);
    expect($moves)->toHaveCount(2)
        ->and($moves->first()->to_box_id)->toBe($doc->current_box_id)
        ->and($moves->last()->to_box_id)->toBe($insitu5->id);

    // The unparseable cell created no box.
    expect(mv_box('IN_SITU', 'NoNumberHere'))->toBeNull();
});
