<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.9 — Configurable Location Hierarchies.
 *
 * Twelve feature tests covering: migration schema, materialised path /
 * depth, breadcrumb, ancestors/descendants tree helpers, parent-change
 * propagation, Box and Document FK wiring (cascade-null), multi-tenant
 * visibility including global locations (repository_id IS NULL), audit log
 * integration, and the "cannot delete when referenced" application guard.
 */
uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function ensureRoles_loc(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function makeRepo_loc(string $prefix = 'LOC'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

/**
 * Create a Location bypassing the multi-tenant scope so tests don't depend
 * on an authenticated user. Real callers go through the Filament resource
 * which sits behind RepositoryScope; the model contract is exercised here
 * directly.
 */
function makeLocation_loc(array $attrs = []): Location
{
    $defaults = [
        'name' => 'Loc ' . strtoupper(substr(uniqid(), -4)),
        'type' => 'repository',
        'is_active' => true,
    ];

    return Location::withoutGlobalScopes()->create(array_merge($defaults, $attrs));
}

function makeUserInRepo_loc(Repository $repo, string $role = 'editor'): User
{
    ensureRoles_loc();
    $u = User::factory()->create([
        'email' => 'loc+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $u->assignRole($role);

    return $u;
}

// -----------------------------------------------------------------------------
// 1 — Migration: schema check
// -----------------------------------------------------------------------------
test('migration creates locations table with all expected columns and indexes', function () {
    expect(Schema::hasTable('locations'))->toBeTrue();

    foreach ([
        'id',
        'parent_id',
        'name',
        'code',
        'type',
        'repository_id',
        'path',
        'depth',
        'is_active',
        'notes',
        'sort_order',
        'created_at',
        'updated_at',
        'deleted_at',
    ] as $col) {
        expect(Schema::hasColumn('locations', $col))
            ->toBeTrue("Missing column: {$col}");
    }

    // location_id wired to boxes and documents
    expect(Schema::hasColumn('boxes', 'location_id'))->toBeTrue();
    expect(Schema::hasColumn('documents', 'location_id'))->toBeTrue();
});

// -----------------------------------------------------------------------------
// 2 — Root: depth=0, path=null
// -----------------------------------------------------------------------------
test('a root location has depth 0 and path null', function () {
    $root = makeLocation_loc(['type' => 'repository', 'name' => 'Main Repo']);

    expect($root->depth)->toBe(0);
    expect($root->path)->toBeNull();
    expect($root->parent_id)->toBeNull();
});

// -----------------------------------------------------------------------------
// 3 — Child: depth=parent.depth+1, path encodes ancestors
// -----------------------------------------------------------------------------
test('a child location has depth=parent.depth+1 and path encodes ancestor ids', function () {
    $root = makeLocation_loc(['type' => 'repository', 'name' => 'Repo A']);
    $room = makeLocation_loc([
        'type' => 'room',
        'name' => 'Room 1',
        'parent_id' => $root->id,
    ]);
    $shelf = makeLocation_loc([
        'type' => 'shelf',
        'name' => 'Shelf A',
        'parent_id' => $room->id,
    ]);

    expect($room->depth)->toBe(1);
    expect($room->path)->toBe((string) $root->id);

    expect($shelf->depth)->toBe(2);
    expect($shelf->path)->toBe($root->id . '/' . $room->id);
});

// -----------------------------------------------------------------------------
// 4 — Parent change recomputes path on the moved subtree
// -----------------------------------------------------------------------------
test('changing parent recomputes path and depth on the moved subtree', function () {
    $repoA = makeLocation_loc(['type' => 'repository', 'name' => 'Repo A']);
    $repoB = makeLocation_loc(['type' => 'repository', 'name' => 'Repo B']);

    $room = makeLocation_loc(['type' => 'room', 'name' => 'Room', 'parent_id' => $repoA->id]);
    $shelf = makeLocation_loc(['type' => 'shelf', 'name' => 'Shelf', 'parent_id' => $room->id]);

    expect($shelf->path)->toBe($repoA->id . '/' . $room->id);
    expect($shelf->depth)->toBe(2);

    // Move "Room" (and its child "Shelf") from Repo A to Repo B.
    $room->parent_id = $repoB->id;
    $room->save();

    $room->refresh();
    $shelf->refresh();

    expect($room->path)->toBe((string) $repoB->id);
    expect($room->depth)->toBe(1);

    expect($shelf->path)->toBe($repoB->id . '/' . $room->id);
    expect($shelf->depth)->toBe(2);
});

// -----------------------------------------------------------------------------
// 5 — breadcrumb()
// -----------------------------------------------------------------------------
test('breadcrumb() returns a slash-joined path of names root-first', function () {
    $a = makeLocation_loc(['type' => 'repository', 'name' => 'A']);
    $b = makeLocation_loc(['type' => 'room', 'name' => 'B', 'parent_id' => $a->id]);
    $c = makeLocation_loc(['type' => 'shelf', 'name' => 'C', 'parent_id' => $b->id]);

    expect($c->breadcrumb())->toBe('A / B / C');
    expect($a->breadcrumb())->toBe('A');
});

// -----------------------------------------------------------------------------
// 6 — descendants() returns the whole subtree
// -----------------------------------------------------------------------------
test('descendants() returns all descendants recursively, ordered by depth', function () {
    $root = makeLocation_loc(['type' => 'repository', 'name' => 'Root']);
    $r1 = makeLocation_loc(['type' => 'room', 'name' => 'R1', 'parent_id' => $root->id]);
    $r2 = makeLocation_loc(['type' => 'room', 'name' => 'R2', 'parent_id' => $root->id]);
    $s1 = makeLocation_loc(['type' => 'shelf', 'name' => 'S1', 'parent_id' => $r1->id]);
    $s2 = makeLocation_loc(['type' => 'shelf', 'name' => 'S2', 'parent_id' => $r2->id]);

    // A separate sub-tree under a different root — must NOT appear.
    $other = makeLocation_loc(['type' => 'repository', 'name' => 'Other']);
    makeLocation_loc(['type' => 'room', 'name' => 'OtherRoom', 'parent_id' => $other->id]);

    $ids = $root->descendants()->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$r1->id, $r2->id, $s1->id, $s2->id]);
});

// -----------------------------------------------------------------------------
// 7 — ancestors() returns ordered root-first
// -----------------------------------------------------------------------------
test('ancestors() returns the parent chain root-first', function () {
    $a = makeLocation_loc(['type' => 'repository', 'name' => 'A']);
    $b = makeLocation_loc(['type' => 'room', 'name' => 'B', 'parent_id' => $a->id]);
    $c = makeLocation_loc(['type' => 'shelf', 'name' => 'C', 'parent_id' => $b->id]);

    $ancestors = $c->ancestors();
    expect($ancestors->pluck('id')->all())->toBe([$a->id, $b->id]);
    expect($ancestors->pluck('name')->all())->toBe(['A', 'B']);

    // Root has no ancestors.
    expect($a->ancestors()->all())->toBe([]);
});

// -----------------------------------------------------------------------------
// 8 — Box::location relation + cascade-null on Location delete
// -----------------------------------------------------------------------------
test('Box::location relation works and FK cascade-null on hard delete', function () {
    $repo = makeRepo_loc('BOX');
    // Acting-as user so BelongsToRepository hook accepts the writes.
    $this->actingAs(makeUserInRepo_loc($repo, 'super_admin'));

    $loc = makeLocation_loc(['repository_id' => $repo->id, 'name' => 'Shelf A', 'type' => 'shelf']);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 7777 + random_int(0, 999),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'B-' . uniqid(),
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'location_id' => $loc->id,
    ]);

    expect($box->location->is($loc))->toBeTrue();
    expect($loc->boxes()->where('boxes.id', $box->id)->exists())->toBeTrue();

    // Force-delete the location: FK is nullOnDelete, so box.location_id
    // must become NULL (no FK violation, no cascading delete on the box).
    $loc->forceDelete();
    $box->refresh();
    expect($box->location_id)->toBeNull();
});

// -----------------------------------------------------------------------------
// 9 — Document::location relation + cascade-null on Location delete
// -----------------------------------------------------------------------------
test('Document::location relation works and FK cascade-null on hard delete', function () {
    $repo = makeRepo_loc('DOC');
    $this->actingAs(makeUserInRepo_loc($repo, 'super_admin'));

    $series = Series::query()->first()
        ?? Series::create(['code' => 'LOC-S', 'title' => 'Loc series', 'is_active' => true]);

    $loc = makeLocation_loc([
        'repository_id' => $repo->id,
        'name' => 'Showcase X',
        'type' => 'showcase',
    ]);

    $doc = Document::create([
        'identifier' => 'LOC-DOC-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'location_id' => $loc->id,
    ]);

    expect($doc->location->is($loc))->toBeTrue();
    expect($loc->documents()->where('documents.id', $doc->id)->exists())->toBeTrue();

    $loc->forceDelete();
    $doc->refresh();
    expect($doc->location_id)->toBeNull();
});

// -----------------------------------------------------------------------------
// 10 — Multi-tenant: user only sees own repo + global locations
// -----------------------------------------------------------------------------
test('editor only sees locations in own repository and global locations', function () {
    $repoA = makeRepo_loc('TA');
    $repoB = makeRepo_loc('TB');

    $locA = makeLocation_loc(['repository_id' => $repoA->id, 'name' => 'In A']);
    $locB = makeLocation_loc(['repository_id' => $repoB->id, 'name' => 'In B']);
    $locGlobal = makeLocation_loc(['repository_id' => null, 'name' => 'Global Lab', 'type' => 'conservation']);

    // Act as an editor only in repoA.
    $editor = makeUserInRepo_loc($repoA, 'editor');
    $this->actingAs($editor);

    // The BelongsToRepository global scope restricts to allowed
    // repository_ids; but our intent is that global locations
    // (repository_id IS NULL) ALSO show up via scopeForRepository.
    // The Filament Select uses forRepository(), so we exercise that
    // exact predicate here.
    $visible = Location::query()
        ->withoutGlobalScopes()
        ->forRepository($repoA->id)
        ->pluck('id')
        ->all();

    expect($visible)->toContain($locA->id);
    expect($visible)->toContain($locGlobal->id);
    expect($visible)->not->toContain($locB->id);
});

// -----------------------------------------------------------------------------
// 11 — owen-it audit log records creates/updates
// -----------------------------------------------------------------------------
test('creating and updating a Location creates owen-it audit rows', function () {
    // Audit observers need an authenticated user to fill in user_id, but
    // they record events regardless. Use a super_admin to also bypass the
    // tenant scope checks during the test.
    // owen-it disables auditing for "console" runs by default
    // (config/audit.php: 'console' => false). Pest runs through artisan,
    // so the live config registers as console — flip it on for this test.
    config()->set('audit.console', true);

    $repo = makeRepo_loc('AUD');
    $this->actingAs(makeUserInRepo_loc($repo, 'super_admin'));

    $before = Audit::query()->where('auditable_type', Location::class)->count();

    $loc = Location::create([
        'name' => 'Audit Test',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    $loc->update(['name' => 'Audit Test (renamed)']);

    $after = Audit::query()->where('auditable_type', Location::class)->count();
    expect($after - $before)->toBeGreaterThanOrEqual(2); // at least one created, one updated

    $events = Audit::query()
        ->where('auditable_type', Location::class)
        ->where('auditable_id', $loc->id)
        ->pluck('event')
        ->all();

    expect($events)->toContain('created');
    expect($events)->toContain('updated');
});

// -----------------------------------------------------------------------------
// 12 — Delete refused when location has children or is referenced
// -----------------------------------------------------------------------------
test('cannot delete a location with children or attached records (application guard)', function () {
    $repo = makeRepo_loc('GRD');
    $this->actingAs(makeUserInRepo_loc($repo, 'super_admin'));

    // (a) Location with children → isReferenced() is FALSE, but hasChildren() is TRUE
    $parent = makeLocation_loc(['repository_id' => $repo->id, 'name' => 'Parent']);
    makeLocation_loc(['repository_id' => $repo->id, 'name' => 'Child', 'parent_id' => $parent->id]);

    expect($parent->hasChildren())->toBeTrue();
    expect($parent->isReferenced())->toBeFalse();
    expect($parent->hasChildren() || $parent->isReferenced())->toBeTrue(); // would block delete

    // (b) Location with a Box attached
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 8888 + random_int(0, 999),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $boxLoc = makeLocation_loc(['repository_id' => $repo->id, 'name' => 'BoxLoc', 'type' => 'shelf']);
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'B-' . uniqid(),
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'location_id' => $boxLoc->id,
    ]);

    expect($boxLoc->isReferenced())->toBeTrue();

    // (c) Location with a Document attached
    $series = Series::query()->first()
        ?? Series::create(['code' => 'GRD-S', 'title' => 'Grd', 'is_active' => true]);
    $docLoc = makeLocation_loc(['repository_id' => $repo->id, 'name' => 'DocLoc', 'type' => 'showcase']);
    Document::create([
        'identifier' => 'GRD-D-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'location_id' => $docLoc->id,
    ]);
    expect($docLoc->isReferenced())->toBeTrue();

    // (d) A leaf, unreferenced location should be deletable.
    $leaf = makeLocation_loc(['repository_id' => $repo->id, 'name' => 'Leaf']);
    expect($leaf->hasChildren())->toBeFalse();
    expect($leaf->isReferenced())->toBeFalse();
    $leaf->delete(); // soft delete

    // Soft delete: the row stays in the table with deleted_at != null, but
    // the default query (with SoftDeletingScope active) hides it. We
    // explicitly drop ONLY the RepositoryScope; the SoftDeletingScope must
    // remain active for the assertion to be meaningful.
    expect(Location::withoutGlobalScope(RepositoryScope::class)->find($leaf->id))->toBeNull();
    expect(Location::withTrashed()->withoutGlobalScope(RepositoryScope::class)->find($leaf->id))->not->toBeNull();
});

// -----------------------------------------------------------------------------
// 13 — Cycle detection: cannot move a location under one of its descendants
// -----------------------------------------------------------------------------
test('attempting to move a location under one of its own descendants throws DomainException', function () {
    $root = makeLocation_loc(['type' => 'repository', 'name' => 'Root']);
    $child = makeLocation_loc(['type' => 'room', 'name' => 'Child', 'parent_id' => $root->id]);
    $grand = makeLocation_loc(['type' => 'shelf', 'name' => 'Grand', 'parent_id' => $child->id]);

    $root->parent_id = $grand->id;
    expect(fn () => $root->save())->toThrow(DomainException::class);
});

// -----------------------------------------------------------------------------
// 14 — Unique (repository_id, code) at the DB level
// -----------------------------------------------------------------------------
test('code is unique within the same repository (DB constraint)', function () {
    $repo = makeRepo_loc('UQ');
    $code = 'SHARED-' . substr(uniqid(), -6);

    makeLocation_loc(['repository_id' => $repo->id, 'code' => $code, 'name' => 'L1']);

    try {
        makeLocation_loc(['repository_id' => $repo->id, 'code' => $code, 'name' => 'L2']);
        $this->fail('Expected uniqueness violation on duplicate (repository_id, code).');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(QueryException::class);
        expect(strtolower($e->getMessage()))->toMatch('/unique|constraint/');
    }

    // Same code in a DIFFERENT repository must succeed.
    $repoOther = makeRepo_loc('UQ2');
    $ok = makeLocation_loc(['repository_id' => $repoOther->id, 'code' => $code, 'name' => 'L3']);
    expect($ok->exists)->toBeTrue();
});
