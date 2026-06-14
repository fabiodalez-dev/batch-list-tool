<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\SendToDisinfestationAction;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * "Currently in disinfestation" workflow — RFQ App.1 #5 (disinfestation
 * lifecycle).
 *
 * Covers the round-trip of a document through the fumigation cycle:
 *
 *   default → SendToDisinfestation → (chamber) → MarkDisinfested
 *   IN          OUT, flag=true        IN, flag=false, date stamped
 *
 * The filter test confirms the `is_in_disinfestation` ternary filter on the
 * ListDocuments table narrows the resultset to the rows currently in flight.
 * The bulk-atomicity test mirrors the existing pattern for the other 13 bulk
 * actions: one row throwing mid-bulk must NOT roll back the rows that
 * succeeded before it.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* -------------------------------------------------------------------------
 |  Local helpers (kept separate from DocumentActionsTest's helpers to avoid
 |  cross-file name collisions when Pest collects both files).
 * ------------------------------------------------------------------------- */

function disinfest_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'disinfest-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function disinfest_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'DI_' . substr(uniqid(), -6),
    ]);
}

function disinfest_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'DIS_' . substr(uniqid(), -4)],
        ['title' => 'Disinfest test series', 'is_active' => true],
    );
}

function disinfest_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'DDOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function disinfest_asColl(Document ...$docs): EloquentCollection
{
    /** @var EloquentCollection<int, Document> $c */
    $c = new EloquentCollection($docs);

    return $c;
}

/**
 * Same reflection helper as DocumentActionsTest::runAction(), duplicated
 * locally so this file is self-contained and the two test files don't
 * collide on global helper names.
 */
function disinfest_runAction(Action|BulkAction $action, array $named): void
{
    $closure = (fn () => $this->action)->call($action);

    throw_unless($closure instanceof Closure, RuntimeException::class, 'Action closure missing');

    $ref = new ReflectionFunction($closure);
    $args = [];
    foreach ($ref->getParameters() as $p) {
        $name = $p->getName();
        if (array_key_exists($name, $named)) {
            $args[] = $named[$name];
            continue;
        }
        if ($p->isOptional()) {
            $args[] = $p->getDefaultValue();
            continue;
        }

        throw new RuntimeException("Cannot bind action parameter: {$name}");
    }
    $closure(...$args);
}

/* -------------------------------------------------------------------------
 |  Test 1 — Default state
 * ------------------------------------------------------------------------- */

test('a fresh document has is_in_disinfestation = false by default', function () {
    $repo = disinfest_repo();
    $series = disinfest_series();

    $doc = disinfest_doc($repo->id, $series->id);
    $doc->refresh();

    expect($doc->is_in_disinfestation)->toBeFalse();
});

/* -------------------------------------------------------------------------
 |  Test 2 — SendToDisinfestationAction flips the flag, sets OUT, audits
 * ------------------------------------------------------------------------- */

test('SendToDisinfestationAction flips is_in_disinfestation, sets barcode OUT, writes audit row', function () {
    $this->actingAs(disinfest_actAsSuperAdmin());

    $repo = disinfest_repo();
    $series = disinfest_series();
    $doc = disinfest_doc($repo->id, $series->id, [
        'is_in_disinfestation' => false,
        'barcode_status' => 'IN',
    ]);

    disinfest_runAction(SendToDisinfestationAction::make(), [
        'record' => $doc,
    ]);

    $doc->refresh();
    expect($doc->is_in_disinfestation)->toBeTrue()
        ->and($doc->barcode_status)->toBe('OUT');

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'document.sent_to_disinfestation')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->new_values['is_in_disinfestation'] ?? null)->toBeTrue();
    expect($audit->new_values['barcode_status'] ?? null)->toBe('OUT');
});

/* -------------------------------------------------------------------------
 |  Test 3 — MarkDisinfestedAction closes the cycle
 * ------------------------------------------------------------------------- */

test('MarkDisinfestedAction clears is_in_disinfestation, sets barcode IN, stamps disinfestation_date', function () {
    $this->actingAs(disinfest_actAsSuperAdmin());

    $repo = disinfest_repo();
    $series = disinfest_series();
    $doc = disinfest_doc($repo->id, $series->id, [
        'is_in_disinfestation' => true,
        'barcode_status' => 'OUT',
        'disinfestation_date' => null,
    ]);

    $today = now()->toDateString();
    disinfest_runAction(MarkDisinfestedAction::make(), [
        'record' => $doc,
        'data' => ['disinfestation_date' => $today],
    ]);

    $doc->refresh();
    expect($doc->is_in_disinfestation)->toBeFalse()
        ->and($doc->barcode_status)->toBe('IN')
        ->and($doc->disinfestation_date?->toDateString())->toBe($today);

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'document.marked_disinfested')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->new_values['is_in_disinfestation'] ?? null)->toBeFalse();
    expect($audit->new_values['barcode_status'] ?? null)->toBe('IN');
});

/* -------------------------------------------------------------------------
 |  Test 4 — Ternary filter narrows the list view
 * ------------------------------------------------------------------------- */

test('the is_in_disinfestation ternary filter narrows ListDocuments correctly', function () {
    $this->actingAs(disinfest_actAsSuperAdmin());

    $repo = disinfest_repo();
    $series = disinfest_series();

    $inFlight = disinfest_doc($repo->id, $series->id, [
        'is_in_disinfestation' => true,
    ]);
    $atRest = disinfest_doc($repo->id, $series->id, [
        'is_in_disinfestation' => false,
    ]);

    // true → only in-flight rows visible
    Livewire::test(ListDocuments::class)
        ->set('tableFilters.is_in_disinfestation.value', true)
        ->assertCanSeeTableRecords([$inFlight])
        ->assertCanNotSeeTableRecords([$atRest]);

    // false → only at-rest rows visible
    Livewire::test(ListDocuments::class)
        ->set('tableFilters.is_in_disinfestation.value', false)
        ->assertCanSeeTableRecords([$atRest])
        ->assertCanNotSeeTableRecords([$inFlight]);
});

/* -------------------------------------------------------------------------
 |  Test 5 — Bulk-action atomicity per row
 * ------------------------------------------------------------------------- */

test('SendToDisinfestationAction bulk is atomic per row (one failure does not roll back others)', function () {
    $this->actingAs(disinfest_actAsSuperAdmin());

    $repo = disinfest_repo();
    $series = disinfest_series();

    // Two healthy documents that should succeed.
    $okA = disinfest_doc($repo->id, $series->id);
    $okB = disinfest_doc($repo->id, $series->id);

    // One document we deliberately sabotage via a temporary model event
    // listener that throws when this specific id tries to save. This is the
    // most portable way to make one row fail mid-bulk regardless of DB
    // driver (sqlite/MySQL behave very differently for constraint errors).
    $broken = disinfest_doc($repo->id, $series->id);
    $brokenId = $broken->id;

    Document::saving(function (Document $d) use ($brokenId): void {
        throw_if($d->getKey() === $brokenId, RuntimeException::class, 'intentional test failure on broken row');
    });

    try {
        disinfest_runAction(SendToDisinfestationAction::bulk(), [
            'records' => disinfest_asColl($okA, $broken, $okB),
        ]);
    } finally {
        // Wipe the saving listener so it doesn't leak into other tests in
        // the same process. flushEventListeners drops *all* listeners on
        // Document — fine for an in-memory RefreshDatabase test run because
        // the model's own booted() hook will reattach on next class boot.
        Document::flushEventListeners();
    }

    // okA and okB must have been committed; the broken row stays untouched.
    $okA->refresh();
    $okB->refresh();
    expect($okA->is_in_disinfestation)->toBeTrue()
        ->and($okA->barcode_status)->toBe('OUT')
        ->and($okB->is_in_disinfestation)->toBeTrue()
        ->and($okB->barcode_status)->toBe('OUT');

    $brokenFresh = Document::withoutGlobalScope(RepositoryScope::class)->find($brokenId);
    expect($brokenFresh)->not->toBeNull()
        ->and((bool) $brokenFresh->is_in_disinfestation)->toBeFalse();
});
