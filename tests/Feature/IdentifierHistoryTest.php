<?php

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\RelationManagers\IdentifierHistoryRelationManager;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Observers\DocumentObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| PR #8 — Document identifier history
|--------------------------------------------------------------------------
|
| 25 feature tests covering migration schema, model contract, the observer
| that auto-captures identifier changes, the Filament RelationManager,
| Filament global search, multi-tenant isolation via BelongsToRepository,
| and the integration with owen-it/laravel-auditing.
|
*/

// -- helpers -----------------------------------------------------------------

/**
 * Build a Document without triggering the per-tenant global scope concerns.
 */
function makeDocument(array $overrides = []): Document
{
    return Document::factory()->create($overrides);
}

// 1 ---------------------------------------------------------------------------
test('migration creates document_identifier_history table with all expected columns', function () {
    expect(Schema::hasTable('document_identifier_history'))->toBeTrue();

    foreach ([
        'id',
        'document_id',
        'previous_identifier',
        'new_identifier',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
        'created_at',
        'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('document_identifier_history', $col))
            ->toBeTrue("Missing column: {$col}");
    }
});

// 2 ---------------------------------------------------------------------------
test('model exposes the correct fillable and casts', function () {
    $m = new DocumentIdentifierHistory();

    expect($m->getFillable())->toEqualCanonicalizing([
        'document_id',
        'previous_identifier',
        'new_identifier',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
    ]);

    expect($m->getCasts())->toHaveKey('changed_at');
    expect($m->getCasts()['changed_at'])->toBe('datetime');
});

// 3 ---------------------------------------------------------------------------
test('observer records a history row when identifier changes on update', function () {
    $doc = makeDocument(['identifier' => 'R1']);

    $doc->update(['identifier' => 'R1-new']);

    $rows = DocumentIdentifierHistory::where('document_id', $doc->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_identifier)->toBe('R1');
    expect($rows->first()->new_identifier)->toBe('R1-new');
});

// 4 ---------------------------------------------------------------------------
test('observer does NOT record when identifier is unchanged', function () {
    $doc = makeDocument(['identifier' => 'R2']);

    $doc->update(['notes' => 'unrelated change']);

    expect(DocumentIdentifierHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 5 ---------------------------------------------------------------------------
test('observer does NOT record on initial create', function () {
    $doc = makeDocument(['identifier' => 'R3']);

    expect(DocumentIdentifierHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 6 ---------------------------------------------------------------------------
test('whitespace-only identifier change is ignored', function () {
    $doc = makeDocument(['identifier' => 'R4']);

    $doc->update(['identifier' => '  R4  ']);

    expect(DocumentIdentifierHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 7 ---------------------------------------------------------------------------
test('multiple identifier changes produce multiple rows in chronological order', function () {
    $doc = makeDocument(['identifier' => 'R5']);

    $doc->update(['identifier' => 'R5-a']);
    $doc->refresh();
    $doc->update(['identifier' => 'R5-b']);
    $doc->refresh();
    $doc->update(['identifier' => 'R5-c']);

    $rows = $doc->identifierHistory()->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('previous_identifier')->all())
        ->toBe(['R5', 'R5-a', 'R5-b']);
    expect($rows->pluck('new_identifier')->all())
        ->toBe(['R5-a', 'R5-b', 'R5-c']);
});

// 8 ---------------------------------------------------------------------------
test('recordChange() uses auth user id when userId is null', function () {
    $user = User::factory()->create();
    $doc = makeDocument();

    $this->actingAs($user);

    $row = DocumentIdentifierHistory::recordChange($doc, 'OLD', 'NEW');

    expect($row->changed_by_user_id)->toBe($user->id);
});

// 9 ---------------------------------------------------------------------------
test('recordChange() respects explicit userId argument', function () {
    $authUser = User::factory()->create();
    $explicitUser = User::factory()->create();
    $doc = makeDocument();

    $this->actingAs($authUser);

    $row = DocumentIdentifierHistory::recordChange(
        document: $doc,
        previous: 'OLD',
        new: 'NEW',
        userId: $explicitUser->id,
    );

    expect($row->changed_by_user_id)->toBe($explicitUser->id);
});

// 10 --------------------------------------------------------------------------
test('repository_id on the history row is copied from the parent document', function () {
    $repo = Repository::factory()->create();
    $doc = makeDocument(['repository_id' => $repo->id, 'identifier' => 'R10']);

    $doc->update(['identifier' => 'R10-new']);

    $row = DocumentIdentifierHistory::where('document_id', $doc->id)->first();
    expect($row->repository_id)->toBe($repo->id);
});

// 11 --------------------------------------------------------------------------
test('deleting a document cascades and removes its identifier history', function () {
    $doc = makeDocument(['identifier' => 'R11']);
    $doc->update(['identifier' => 'R11-new']);
    expect(DocumentIdentifierHistory::where('document_id', $doc->id)->count())->toBe(1);

    // forceDelete to bypass SoftDeletes and actually trigger the FK cascade.
    $doc->forceDelete();

    expect(DocumentIdentifierHistory::where('document_id', $doc->id)->count())
        ->toBe(0);
});

// 12 --------------------------------------------------------------------------
test('RelationManager class loads and exposes the configured relationship', function () {
    expect(class_exists(IdentifierHistoryRelationManager::class))->toBeTrue();

    $rm = new ReflectionClass(IdentifierHistoryRelationManager::class);
    $relProp = $rm->getProperty('relationship');
    $relProp->setAccessible(true);
    // It's a static string property on the class.
    expect($relProp->getValue())->toBe('identifierHistory');
});

// 13 --------------------------------------------------------------------------
test('global search includes identifierHistory.previous_identifier', function () {
    expect(DocumentResource::getGloballySearchableAttributes())
        ->toContain('identifierHistory.previous_identifier');
});

// 14 --------------------------------------------------------------------------
test('multi-tenant scope: history rows are filtered by the user repository', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $docA = makeDocument(['repository_id' => $repoA->id, 'identifier' => 'A1']);
    $docB = makeDocument(['repository_id' => $repoB->id, 'identifier' => 'B1']);

    $docA->update(['identifier' => 'A1-new']);
    $docB->update(['identifier' => 'B1-new']);

    // Plain user attached only to repoA.
    $user = User::factory()->create(['default_repository_id' => $repoA->id]);
    $user->repositories()->attach($repoA->id);
    $this->actingAs($user);

    $visible = DocumentIdentifierHistory::query()->pluck('repository_id')->unique()->values();
    expect($visible->all())->toBe([$repoA->id]);
});

// 15 --------------------------------------------------------------------------
test('audit trail: inserting a history row triggers an owen-it Audit record', function () {
    // owen-it/laravel-auditing skips console events by default; force-enable
    // for this assertion so we can prove the audit pipeline is wired up.
    config(['audit.console' => true]);

    $doc = makeDocument(['identifier' => 'R15']);

    Audit::query()->delete(); // clean slate for the assertion

    $doc->update(['identifier' => 'R15-new']);

    $audits = Audit::where('auditable_type', (new DocumentIdentifierHistory())->getMorphClass())->get();
    expect($audits)->not->toBeEmpty();
});

// 16 --------------------------------------------------------------------------
test('reason field is optional and persists when provided', function () {
    $doc = makeDocument();

    $row = DocumentIdentifierHistory::recordChange($doc, 'X', 'Y');
    expect($row->reason)->toBeNull();

    $row2 = DocumentIdentifierHistory::recordChange($doc, 'Y', 'Z', 'reconciliation 2026-05');
    expect($row2->reason)->toBe('reconciliation 2026-05');
});

// 17 --------------------------------------------------------------------------
test('changed_at defaults to "now" when not provided explicitly', function () {
    $doc = makeDocument();

    $before = now()->subSecond();
    $row = DocumentIdentifierHistory::recordChange($doc, 'A', 'B');
    $after = now()->addSecond();

    expect($row->changed_at->between($before, $after))->toBeTrue();
});

// 18 --------------------------------------------------------------------------
test('changed_at can be explicitly back-dated via the factory', function () {
    // Use a clean second-precision timestamp because some DB drivers
    // (SQLite default datetime) drop sub-second fractions on round-trip.
    $when = now()->subYears(3)->startOfSecond();
    $row = DocumentIdentifierHistory::factory()->backDatedTo($when)->create();

    expect($row->changed_at->timestamp)->toBe($when->timestamp);
});

// 19 --------------------------------------------------------------------------
test('factory generates valid persistable records', function () {
    $row = DocumentIdentifierHistory::factory()->create();

    expect($row->exists)->toBeTrue();
    expect($row->document_id)->toBeInt();
    expect($row->repository_id)->toBeInt();
    expect($row->previous_identifier)->toBeString();
});

// 20 --------------------------------------------------------------------------
test('previousIdentifiers() returns distinct values only', function () {
    $doc = makeDocument(['identifier' => 'R20']);

    // Manually insert duplicates of the same previous identifier.
    DocumentIdentifierHistory::recordChange($doc, 'R20-old', 'R20');
    DocumentIdentifierHistory::recordChange($doc, 'R20-old', 'R20-bis');
    DocumentIdentifierHistory::recordChange($doc, 'R20-elder', 'R20-old');

    $distinct = $doc->previousIdentifiers();

    expect($distinct->all())
        ->toEqualCanonicalizing(['R20-old', 'R20-elder']);
});

// 21 --------------------------------------------------------------------------
test('identifierHistory relation is ordered descending by changed_at', function () {
    $doc = makeDocument();

    $a = DocumentIdentifierHistory::factory()
        ->backDatedTo(now()->subDays(10))
        ->create(['document_id' => $doc->id]);

    $b = DocumentIdentifierHistory::factory()
        ->backDatedTo(now()->subDays(5))
        ->create(['document_id' => $doc->id]);

    $c = DocumentIdentifierHistory::factory()
        ->backDatedTo(now()->subDay())
        ->create(['document_id' => $doc->id]);

    $ordered = $doc->identifierHistory()->get();
    expect($ordered->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

// 22 --------------------------------------------------------------------------
test('null -> null transition is skipped by the observer', function () {
    // Force a document with a null identifier (we bypass model validation here).
    $doc = makeDocument(['identifier' => 'R22']);

    // Simulate the observer directly with both values null.
    $observer = new DocumentObserver();
    $skipReflection = new ReflectionMethod(DocumentObserver::class, 'shouldSkip');
    $skipReflection->setAccessible(true);

    expect($skipReflection->invoke($observer, null, null))->toBeTrue();
    expect($skipReflection->invoke($observer, '  ', "\t"))->toBeTrue();
    expect($skipReflection->invoke($observer, 'R22', 'R23'))->toBeFalse();
});

// 23 --------------------------------------------------------------------------
test('history row is created with the authenticated user when present', function () {
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['default_repository_id' => $repo->id]);
    // Attach so the BelongsToRepository scope grants read access to the row.
    $user->repositories()->attach($repo->id);

    $this->actingAs($user);

    $doc = makeDocument(['identifier' => 'R23', 'repository_id' => $repo->id]);
    $doc->update(['identifier' => 'R23-new']);

    $row = DocumentIdentifierHistory::where('document_id', $doc->id)->first();
    expect($row)->not->toBeNull();
    expect($row->changedBy)->not->toBeNull();
    expect($row->changedBy->id)->toBe($user->id);
});

// 24 --------------------------------------------------------------------------
test('history row falls back to null user when no auth context', function () {
    auth()->logout();
    $doc = makeDocument(['identifier' => 'R24']);
    $doc->update(['identifier' => 'R24-new']);

    $row = DocumentIdentifierHistory::where('document_id', $doc->id)->first();
    expect($row->changed_by_user_id)->toBeNull();
});

// 25 --------------------------------------------------------------------------
test('Document model exposes identifierHistory() returning a HasMany', function () {
    $doc = makeDocument();
    $rel = $doc->identifierHistory();

    expect($rel)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    expect($rel->getRelated())->toBeInstanceOf(DocumentIdentifierHistory::class);
});
