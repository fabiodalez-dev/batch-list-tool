<?php

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\RelationManagers\SealNumberHistoryRelationManager;
use App\Models\Document;
use App\Models\DocumentSealNumberHistory;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/*
|--------------------------------------------------------------------------
| RFQ §3.1.5 — Document seal_number history
|--------------------------------------------------------------------------
|
| Feature tests covering the migration schema, model contract, the model
| `booted()` hook that auto-captures seal_number changes, the Filament
| RelationManager, multi-tenant isolation via BelongsToRepository, and the
| factory contract. Mirrors tests/Feature/IdentifierHistoryTest.php in
| shape; deviations are intentional only where the seal_number column
| behaves differently from identifier (e.g. it is nullable, so the
| "create with null seal_number" path is exercisable here).
|
*/

// -- helpers -----------------------------------------------------------------

function makeDocumentForSealHistory(array $overrides = []): Document
{
    return Document::factory()->create($overrides);
}

// 1 ---------------------------------------------------------------------------
test('migration creates document_seal_number_history table with all expected columns', function () {
    expect(Schema::hasTable('document_seal_number_history'))->toBeTrue();

    foreach ([
        'id',
        'document_id',
        'previous_seal_number',
        'new_seal_number',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
        'created_at',
        'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('document_seal_number_history', $col))
            ->toBeTrue("Missing column: {$col}");
    }
});

// 2 ---------------------------------------------------------------------------
test('model exposes the correct fillable and casts', function () {
    $m = new DocumentSealNumberHistory;

    expect($m->getFillable())->toEqualCanonicalizing([
        'document_id',
        'previous_seal_number',
        'new_seal_number',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
    ]);

    expect($m->getCasts())->toHaveKey('changed_at');
    expect($m->getCasts()['changed_at'])->toBe('datetime');
});

// 3 ---------------------------------------------------------------------------
test('booted() hook records a history row when seal_number changes on update', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-001']);

    $doc->update(['seal_number' => 'SEAL-002']);

    $rows = DocumentSealNumberHistory::where('document_id', $doc->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_seal_number)->toBe('SEAL-001');
    expect($rows->first()->new_seal_number)->toBe('SEAL-002');
});

// 4 ---------------------------------------------------------------------------
test('booted() hook does NOT record when seal_number is unchanged', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-003']);

    $doc->update(['notes' => 'unrelated change']);

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 5 ---------------------------------------------------------------------------
test('booted() hook does NOT record on initial create', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-004']);

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 6 ---------------------------------------------------------------------------
test('multiple seal_number changes produce multiple rows in chronological order', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-A']);

    $doc->update(['seal_number' => 'SEAL-B']);
    $doc->refresh();
    $doc->update(['seal_number' => 'SEAL-C']);
    $doc->refresh();
    $doc->update(['seal_number' => 'SEAL-D']);

    $rows = $doc->sealNumberHistory()->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('previous_seal_number')->all())
        ->toBe(['SEAL-A', 'SEAL-B', 'SEAL-C']);
    expect($rows->pluck('new_seal_number')->all())
        ->toBe(['SEAL-B', 'SEAL-C', 'SEAL-D']);
});

// 7 ---------------------------------------------------------------------------
test('previousSealNumbers() returns distinct values only', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-Z']);

    // Manually insert rows with overlapping previous values; the accessor
    // must dedupe in PHP so SQLite collation does not influence the result.
    DocumentSealNumberHistory::recordChange($doc, 'SEAL-old', 'SEAL-Z');
    DocumentSealNumberHistory::recordChange($doc, 'SEAL-old', 'SEAL-Z-bis');
    DocumentSealNumberHistory::recordChange($doc, 'SEAL-elder', 'SEAL-old');

    $distinct = $doc->previousSealNumbers();

    expect($distinct->all())
        ->toEqualCanonicalizing(['SEAL-old', 'SEAL-elder']);
});

// 8 ---------------------------------------------------------------------------
test('sealNumberHistory relation is ordered descending by changed_at', function () {
    $doc = makeDocumentForSealHistory();

    $a = DocumentSealNumberHistory::factory()
        ->backDatedTo(now()->subDays(10))
        ->create(['document_id' => $doc->id, 'repository_id' => $doc->repository_id]);

    $b = DocumentSealNumberHistory::factory()
        ->backDatedTo(now()->subDays(5))
        ->create(['document_id' => $doc->id, 'repository_id' => $doc->repository_id]);

    $c = DocumentSealNumberHistory::factory()
        ->backDatedTo(now()->subDay())
        ->create(['document_id' => $doc->id, 'repository_id' => $doc->repository_id]);

    $ordered = $doc->sealNumberHistory()->get();
    expect($ordered->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

// 9 ---------------------------------------------------------------------------
test('repository_id on the history row is copied from the parent document', function () {
    $repo = Repository::factory()->create();
    $doc = makeDocumentForSealHistory([
        'repository_id' => $repo->id,
        'seal_number' => 'SEAL-R1',
    ]);

    $doc->update(['seal_number' => 'SEAL-R1-new']);

    $row = DocumentSealNumberHistory::where('document_id', $doc->id)->first();
    expect($row->repository_id)->toBe($repo->id);
});

// 10 --------------------------------------------------------------------------
test('multi-tenant scope: history rows are filtered by the user repository', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $docA = makeDocumentForSealHistory([
        'repository_id' => $repoA->id,
        'seal_number' => 'A-SEAL-1',
    ]);
    $docB = makeDocumentForSealHistory([
        'repository_id' => $repoB->id,
        'seal_number' => 'B-SEAL-1',
    ]);

    $docA->update(['seal_number' => 'A-SEAL-1-new']);
    $docB->update(['seal_number' => 'B-SEAL-1-new']);

    // Plain user attached only to repoA — the editor role gets non-admin
    // permissions so the RepositoryScope global scope is enforced.
    $user = User::factory()->create(['default_repository_id' => $repoA->id]);
    $user->assignRole('editor');
    $user->repositories()->attach($repoA->id);
    $this->actingAs($user);

    $visible = DocumentSealNumberHistory::query()
        ->pluck('repository_id')
        ->unique()
        ->values();
    expect($visible->all())->toBe([$repoA->id]);
});

// 11 --------------------------------------------------------------------------
test('soft-deleting a document preserves the seal_number history rows', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-SD']);
    $doc->update(['seal_number' => 'SEAL-SD-new']);

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())->toBe(1);

    // SoftDeletes only flips `deleted_at`; the FK cascade is NOT triggered,
    // so the audit trail must remain intact for forensic queries.
    $doc->delete();

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())
        ->toBe(1);
});

// 12 --------------------------------------------------------------------------
test('force-deleting a document cascades and removes its seal_number history', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-FD']);
    $doc->update(['seal_number' => 'SEAL-FD-new']);
    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())->toBe(1);

    // forceDelete bypasses SoftDeletes and actually triggers the FK cascade.
    $doc->forceDelete();

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 13 --------------------------------------------------------------------------
test('factory generates valid persistable records', function () {
    $row = DocumentSealNumberHistory::factory()->create();

    expect($row->exists)->toBeTrue();
    expect($row->document_id)->toBeInt();
    expect($row->repository_id)->toBeInt();
    expect($row->previous_seal_number)->toBeString();
});

// 14 --------------------------------------------------------------------------
test('Document model exposes sealNumberHistory() returning a HasMany', function () {
    $doc = makeDocumentForSealHistory();
    $rel = $doc->sealNumberHistory();

    expect($rel)->toBeInstanceOf(HasMany::class);
    expect($rel->getRelated())->toBeInstanceOf(DocumentSealNumberHistory::class);
});

// 15 --------------------------------------------------------------------------
test('RelationManager class loads and exposes the configured relationship', function () {
    expect(class_exists(SealNumberHistoryRelationManager::class))->toBeTrue();

    $rm = new ReflectionClass(SealNumberHistoryRelationManager::class);
    $relProp = $rm->getProperty('relationship');
    $relProp->setAccessible(true);
    expect($relProp->getValue())->toBe('sealNumberHistory');
});

// 16 --------------------------------------------------------------------------
test('DocumentResource wires SealNumberHistoryRelationManager into getRelations()', function () {
    expect(DocumentResource::getRelations())
        ->toContain(SealNumberHistoryRelationManager::class);
});

// 17 --------------------------------------------------------------------------
test('whitespace-only seal_number change is ignored by the booted hook', function () {
    $doc = makeDocumentForSealHistory(['seal_number' => 'SEAL-WS']);

    $doc->update(['seal_number' => '  SEAL-WS  ']);

    expect(DocumentSealNumberHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 18 --------------------------------------------------------------------------
test('first-time seal_number assignment (null -> value) is recorded', function () {
    // seal_number is nullable on the documents table — unlike identifier — so
    // the "initial assignment" transition is a real audit event we must keep.
    $doc = makeDocumentForSealHistory(['seal_number' => null]);

    $doc->update(['seal_number' => 'SEAL-FIRST']);

    $row = DocumentSealNumberHistory::where('document_id', $doc->id)->first();
    expect($row)->not->toBeNull();
    // Previous is stored as the empty string because the column is NOT NULL
    // and recordChange() casts null to (string) for that reason.
    expect($row->previous_seal_number)->toBe('');
    expect($row->new_seal_number)->toBe('SEAL-FIRST');
});
