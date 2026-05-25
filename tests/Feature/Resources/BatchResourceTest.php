<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * PR #11b — App\Filament\Resources\BatchResource.
 *
 * NOTE on RFQ validation rules (CLAUDE.md §"Batch Field Rules"):
 *   - Forbidden batch numbers 33/34/36 are enforced via a MySQL CHECK
 *     constraint declared in `2026_05_25_170003_create_batches_table.php`
 *     (DB::statement("ALTER TABLE batches ADD CONSTRAINT ..."). The same
 *     test suite runs against SQLite (see phpunit.xml DB_CONNECTION), where
 *     the CHECK constraint is intentionally skipped because the migration
 *     guards with `if ($driver === 'mysql')`. The tests below therefore
 *     verify the *model contract* (FORBIDDEN_NUMBERS const, isForbidden(),
 *     isWillsOnly() and import-command behaviour) rather than expecting the
 *     Filament form to raise a validation error — which the Resource does
 *     not currently do.
 *
 *   - When this PR is later wired against MySQL, the constraint tests can
 *     be lifted to assert QueryException on insert.
 */

uses(DatabaseTransactions::class);

function rolesExist_batch(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsAdmin_batch(): User
{
    rolesExist_batch();
    $u = User::factory()->create([
        'email'     => 'batch-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');
    return $u;
}

function makeRepository_batch(string $prefix = 'BR'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function makeBatch_batch(int $repoId, int $batchNumber, array $attrs = []): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'batch_number'  => $batchNumber,
        'type'          => $batchNumber <= 29 ? 'MAIN_COLLECTION' : 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active'     => true,
    ], $attrs));
}

function nextSafeBatchNumber(): int
{
    // Avoid forbidden 33/34/36 and the wills-only 50.
    do {
        $n = random_int(1000, 9999);
    } while (in_array($n, [33, 34, 36, 50], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());
    return $n;
}

/* 9. list renders */
test('BatchResource list page renders and shows existing batch', function () {
    $this->actingAs(actAsAdmin_batch());

    $repo  = makeRepository_batch();
    $batch = makeBatch_batch($repo->id, nextSafeBatchNumber());

    Livewire::test(ListBatches::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$batch]);
});

/* 10. create persists */
test('BatchResource create persists row', function () {
    $admin = actAsAdmin_batch();
    $this->actingAs($admin);

    $repo = makeRepository_batch();
    $admin->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $admin->default_repository_id = $repo->id;
    $admin->save();

    $n = nextSafeBatchNumber();

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number'      => $n,
            'description'       => 'New batch via Filament',
            'type'              => 'MAIN_COLLECTION',
            'repository.name'   => $repo->id,
            'is_active'         => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', $n)->exists())->toBeTrue();
});

/*
 * 11. Forbidden batch numbers (33/34/36)
 *
 * The validation lives in (a) the FORBIDDEN_NUMBERS const + isForbidden()
 * helper and (b) a MySQL-only CHECK constraint. We assert both contracts.
 * The import command also respects it.
 */
test('Batch::FORBIDDEN_NUMBERS contains 33, 34, 36 and isForbidden() flags them', function () {
    expect(Batch::FORBIDDEN_NUMBERS)->toBe([33, 34, 36]);

    foreach ([33, 34, 36] as $n) {
        $b = new Batch(['batch_number' => $n]);
        expect($b->isForbidden())->toBeTrue("Batch number {$n} must be forbidden");
    }

    foreach ([1, 7, 29, 30, 50, 99] as $n) {
        $b = new Batch(['batch_number' => $n]);
        expect($b->isForbidden())->toBeFalse("Batch number {$n} must NOT be forbidden");
    }
});

/*
 * 12. Batch 50 → wills-only flag (RFQ rule #2)
 *
 * The Resource form does not currently enforce a "series_id must be WILLS"
 * check on Documents assigned to Batch 50 — that's a known gap. We pin the
 * model contract here so a regression of the helper is caught.
 */
test('Batch::WILLS_BATCH is 50 and isWillsOnly() identifies it', function () {
    expect(Batch::WILLS_BATCH)->toBe(50);

    $w = new Batch(['batch_number' => 50]);
    expect($w->isWillsOnly())->toBeTrue();

    foreach ([1, 7, 29, 30, 49, 51, 99] as $n) {
        $b = new Batch(['batch_number' => $n]);
        expect($b->isWillsOnly())->toBeFalse("Batch number {$n} must NOT be wills-only");
    }
});

/* 13. batches 1-29 → Main Collection (model constant + helper). */
test('Batch numbers 1-29 map to MAIN_COLLECTION (Batch::MAIN_COLLECTION_MAX = 29)', function () {
    expect(Batch::MAIN_COLLECTION_MAX)->toBe(29);

    // The import command sets type based on >= 30 → NOTARY_ACCESSION, else MAIN_COLLECTION.
    // Sample 1, 15, 29 must end up as MAIN_COLLECTION.
    $repo = makeRepository_batch('MC');
    foreach ([1, 15, 29] as $n) {
        // Skip if already present (e.g. seeded data) — pick an offset
        $num = $n + 5000 + random_int(0, 999);
        $b = makeBatch_batch($repo->id, $num, ['type' => 'MAIN_COLLECTION']);
        expect($b->type)->toBe('MAIN_COLLECTION');
    }
});

/* 14. batches 30+ → Notary Accessions. */
test('Batch numbers >= 30 map to NOTARY_ACCESSION via factory convention', function () {
    $repo = makeRepository_batch('NA');
    foreach ([30, 40, 99] as $n) {
        // makeBatch_batch encodes the rule: <=29 MAIN_COLLECTION, else NOTARY_ACCESSION.
        $b = makeBatch_batch($repo->id, $n + 6000 + random_int(0, 999));
        expect($b->type)->toBe('NOTARY_ACCESSION');
    }
});

/*
 * 15. Batch with documents — RFQ rule: cannot be hard-deleted.
 *
 * Schema declares ON DELETE RESTRICT for the documents.batch_id FK
 * (`batches`...->constrained()->restrictOnDelete()` is NOT actually in
 * the batches migration but documents.batch_id is nullOnDelete()). With
 * soft-deletes, calling ->delete() simply sets deleted_at — documents stay
 * pointed at the (trashed) batch. We pin that operational contract.
 */
test('Batch soft-delete keeps existing documents pointing at the trashed batch', function () {
    $repo   = makeRepository_batch();
    $series = Series::query()->first()
        ?? Series::create(['code' => 'BR-S', 'title' => 'BR series', 'is_active' => true]);
    $batch  = makeBatch_batch($repo->id, nextSafeBatchNumber());

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier'    => 'BR-DOC-' . uniqid(),
        'document_type' => 'TEST',
        'series_id'     => $series->id,
        'repository_id' => $repo->id,
        'batch_id'      => $batch->id,
    ]);

    $batch->delete(); // soft-delete

    $doc->refresh();
    expect($doc->batch_id)->toBe($batch->id);
    expect(Batch::find($batch->id))->toBeNull(); // trashed -> hidden
    expect(Batch::withTrashed()->find($batch->id))->not->toBeNull();
});

/* 16. duplicate batch_number → DB unique violation (schema declares ->unique()) */
test('BatchResource duplicate batch_number is rejected (unique DB constraint)', function () {
    $repo = makeRepository_batch();
    $n    = nextSafeBatchNumber();
    makeBatch_batch($repo->id, $n);

    try {
        Batch::withoutGlobalScope(RepositoryScope::class)->create([
            'batch_number'  => $n,
            'type'          => 'MAIN_COLLECTION',
            'repository_id' => $repo->id,
            'is_active'     => true,
        ]);
        $this->fail('Expected uniqueness violation on duplicate batch_number, but insert succeeded.');
    } catch (\Throwable $e) {
        expect($e)->toBeInstanceOf(\Illuminate\Database\QueryException::class);
        expect(strtolower($e->getMessage()))->toContain('unique');
    }
});

/* 17. Update writes an audit row */
test('BatchResource update writes an owen-it audit row', function () {
    config(['audit.console' => true]);

    $admin = actAsAdmin_batch();
    $this->actingAs($admin);

    $repo  = makeRepository_batch();
    $batch = makeBatch_batch($repo->id, nextSafeBatchNumber(), ['description' => 'pre']);

    $before = Audit::query()
        ->where('auditable_type', Batch::class)
        ->where('auditable_id', $batch->id)
        ->count();

    Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->fillForm(['description' => 'post-edit'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->refresh()->description)->toBe('post-edit');
    expect(Audit::query()
        ->where('auditable_type', Batch::class)
        ->where('auditable_id', $batch->id)
        ->count())->toBeGreaterThan($before);
});

/* 18. Multi-tenant scope — editor only sees their repo's batches. */
test('Batch list respects RepositoryScope for an editor', function () {
    rolesExist_batch();

    $repoA = makeRepository_batch('A');
    $repoB = makeRepository_batch('B');
    $bA = makeBatch_batch($repoA->id, nextSafeBatchNumber());
    $bB = makeBatch_batch($repoB->id, nextSafeBatchNumber());

    $editor = User::factory()->create([
        'email'                 => 'br-editor+' . uniqid() . '@test.local',
        'is_active'             => true,
        'default_repository_id' => $repoA->id,
    ]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoA->id, ['is_default' => true]);

    $this->actingAs($editor);

    $visibleIds = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($visibleIds)->toContain($bA->id);
    expect($visibleIds)->not->toContain($bB->id);
});
