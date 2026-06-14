<?php

declare(strict_types=1);

use App\Filament\Actions\Boxes\DestroyBoxAction;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * RFQ Appendix 2 §vii — "Box destroyed" workflow.
 *
 * Covers the model-level eligibility gate ({@see Box::canBeDestroyed()}),
 * the mutator ({@see Box::markDestroyed()}), the two query scopes
 * ({@see Box::scopeDestroyed}, {@see Box::scopeNotDestroyed}) and the
 * authorization rule wired into {@see DestroyBoxAction} (must hold the
 * `delete_box` Shield permission).
 *
 * Documents are created with {@see Document::withoutGlobalScope}(RepositoryScope)
 * because BelongsToRepository scopes documents to the acting user's pivot —
 * in these tests we don't act as a user for the model-level checks, so
 * leaking past the scope is the explicit choice.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* -------------------------------------------------------------------------
 |  Local helpers
 * ------------------------------------------------------------------------- */

function bd_actAs(string $role = 'super_admin'): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'box-destroyed+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function bd_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'BD_' . substr(uniqid(), -6),
    ]);
}

function bd_batch(int $repoId): Batch
{
    do {
        $n = random_int(2000, 8999);
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function bd_box(int $batchId, array $attrs = []): Box
{
    return Box::create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'BX-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batchId,
        'barcode_status' => 'IN',
    ], $attrs));
}

function bd_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'BD_' . substr(uniqid(), -4)],
        ['title' => 'Box-destroyed test series', 'is_active' => true],
    );
}

function bd_doc(int $repoId, int $seriesId, int $boxId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'BDOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
        'current_box_id' => $boxId,
    ], $attrs));
}

/* -------------------------------------------------------------------------
 |  Test 1 — Fully catalogued box → can be destroyed
 * ------------------------------------------------------------------------- */

test('a box where every document has a catalogue_identifier can be destroyed', function () {
    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $series = bd_series();
    $box = bd_box($batch->id);

    bd_doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => 'CAT-001']);
    bd_doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => 'CAT-002']);

    $check = $box->canBeDestroyed();
    expect($check['ok'])->toBeTrue()
        ->and($check['reason'])->toBeNull();
});

/* -------------------------------------------------------------------------
 |  Test 2 — One uncatalogued document blocks destruction
 * ------------------------------------------------------------------------- */

test('a box with at least one uncatalogued document cannot be destroyed', function () {
    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $series = bd_series();
    $box = bd_box($batch->id);

    bd_doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => 'CAT-001']);
    bd_doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => null]); // blocker

    $check = $box->canBeDestroyed();
    expect($check['ok'])->toBeFalse()
        ->and($check['reason'])->toContain('uncatalogued');
});

/* -------------------------------------------------------------------------
 |  Test 3 — Soft-deleted uncatalogued doc still blocks
 * ------------------------------------------------------------------------- */

test('a soft-deleted uncatalogued document still blocks destruction', function () {
    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $series = bd_series();
    $box = bd_box($batch->id);

    $trashed = bd_doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => null]);
    $trashed->delete(); // SoftDelete — physically still in the box.

    // Sanity check: no live documents reference this box, yet canBeDestroyed
    // must still refuse because the trashed (potentially un-trashable) row
    // has no catalogue identifier.
    expect(Document::withoutGlobalScope(RepositoryScope::class)
        ->where('current_box_id', $box->id)->count())->toBe(0);

    $check = $box->canBeDestroyed();
    expect($check['ok'])->toBeFalse()
        ->and($check['reason'])->toContain('uncatalogued');
});

/* -------------------------------------------------------------------------
 |  Test 4 — markDestroyed() stamps the row + writes an audit
 * ------------------------------------------------------------------------- */

test('markDestroyed() writes destroyed_at, destroyed_by_user_id and an audit row', function () {
    // owen-it/laravel-auditing skips console events by default; phpunit /
    // pest run under the cli SAPI so we have to opt-in for the trait to
    // produce a row. Mirrors the same pattern used in DocumentFlagsTest.
    config(['audit.console' => true]);

    $user = bd_actAs('super_admin');
    $this->actingAs($user); // ensures Auditable resolves user_id

    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $box = bd_box($batch->id);

    expect($box->isDestroyed())->toBeFalse();

    // Wipe pre-existing audits so the assertion below targets the
    // markDestroyed() write specifically.
    Audit::query()->delete();

    $box->markDestroyed('shredded on-site, witness J.D.', $user->id);
    $box->refresh();

    expect($box->isDestroyed())->toBeTrue()
        ->and($box->destroyed_at)->not->toBeNull()
        ->and($box->destroyed_by_user_id)->toBe($user->id)
        ->and($box->destroyed_reason)->toBe('shredded on-site, witness J.D.');

    // Auditable writes an `updated` row whose new_values include the three
    // destruction columns. We don't enforce the precise event name because
    // the trait flavours it differently between SQLite/MySQL; we instead
    // assert the column diff landed.
    $audit = Audit::query()
        ->where('auditable_type', (new Box)->getMorphClass())
        ->where('auditable_id', $box->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect(array_key_exists('destroyed_at', $audit->new_values ?? []))->toBeTrue();
});

/* -------------------------------------------------------------------------
 |  Test 5 — already-destroyed box refuses a second markDestroyed
 * ------------------------------------------------------------------------- */

test('an already-destroyed box cannot be destroyed again', function () {
    $user = bd_actAs();
    $this->actingAs($user);

    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $box = bd_box($batch->id);

    $box->markDestroyed(null, $user->id);

    expect($box->fresh()->canBeDestroyed())
        ->toMatchArray(['ok' => false]);

    expect(fn () => $box->markDestroyed('second attempt', $user->id))
        ->toThrow(DomainException::class);
});

/* -------------------------------------------------------------------------
 |  Test 6 — destroyed() / notDestroyed() scopes partition the table
 * ------------------------------------------------------------------------- */

test('Box::destroyed() and Box::notDestroyed() scopes partition the table', function () {
    $user = bd_actAs();
    $this->actingAs($user);

    $repo = bd_repo();
    $batch = bd_batch($repo->id);

    $alive = bd_box($batch->id);
    $dead = bd_box($batch->id);
    $dead->markDestroyed('test', $user->id);

    $destroyedIds = Box::destroyed()->pluck('id')->all();
    $aliveIds = Box::notDestroyed()->pluck('id')->all();

    expect($destroyedIds)->toContain($dead->id)
        ->and($destroyedIds)->not->toContain($alive->id);

    expect($aliveIds)->toContain($alive->id)
        ->and($aliveIds)->not->toContain($dead->id);
});

/* -------------------------------------------------------------------------
 |  Test 7 — Shield permission gate on DestroyBoxAction
 * ------------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 |  Test 8 — race-condition guard: stale in-memory instance is rejected
 |  -----
 |  SQLite has no real row locking but the safety the lockForUpdate +
 |  re-read guarantees is observable functionally: if another writer
 |  destroys the row between the first canBeDestroyed() pass and the
 |  second markDestroyed() call, the re-read inside the transaction must
 |  see destroyed_at set and bail with DomainException — never overwrite.
 * ------------------------------------------------------------------------- */

test('a stale in-memory Box instance cannot overwrite an already-destroyed row', function () {
    $user = bd_actAs();
    $this->actingAs($user);

    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $box = bd_box($batch->id);

    $stale = Box::query()->whereKey($box->id)->first();

    // Simulate a concurrent operator that wins the race.
    $box->markDestroyed('first writer', $user->id);

    // The stale instance still believes destroyed_at is null. The lock-and-
    // re-read inside markDestroyed() must catch the divergence.
    expect(fn () => $stale->markDestroyed('second writer (stale)', $user->id))
        ->toThrow(DomainException::class, 'already marked destroyed');

    // The DB row still carries the FIRST writer's metadata.
    $box->refresh();
    expect($box->destroyed_reason)->toBe('first writer');
});

test('DestroyBoxAction is authorized for delete_box holders and refused otherwise', function () {
    $repo = bd_repo();
    $batch = bd_batch($repo->id);
    $box = bd_box($batch->id);

    $action = DestroyBoxAction::make();

    // Editor: no delete_box permission → not authorized.
    $editor = bd_actAs('editor');
    $this->actingAs($editor);
    expect($editor->can('delete_box'))->toBeFalse();
    expect((bool) $action->record($box)->isAuthorized())->toBeFalse();

    // Super admin: holds delete_box → authorized.
    $superAdmin = bd_actAs('super_admin');
    $this->actingAs($superAdmin);
    expect($superAdmin->can('delete_box'))->toBeTrue();
    expect((bool) $action->record($box)->isAuthorized())->toBeTrue();
});
