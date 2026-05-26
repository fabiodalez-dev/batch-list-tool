<?php

use App\Filament\Resources\DocumentFlagResource;
use App\Filament\Resources\DocumentFlagResource\Pages\ListDocumentFlags;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\FlagsRelationManager;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| RFQ §3.1.12 — Document flags (replacement for spreadsheet colour-coding)
|--------------------------------------------------------------------------
|
| Feature tests covering the migration shape, model contract (mutators,
| scopes, workflow helpers), Document → flags wiring, Filament integration
| (standalone resource + relation manager), audit trail, multi-tenant
| isolation, dashboard widget integration, and Scout search tokenisation.
|
*/

/* -------------------------------------------------------------------------
 |  Helpers
 * ------------------------------------------------------------------------- */

function ensureFlagsRolesExist(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
}

/**
 * Seed the Shield-generated permissions for the DocumentFlag resource and
 * grant them to the super_admin role.
 *
 * Required because phpunit.xml binds tests to a fresh sqlite :memory: DB on
 * every run, so the live MySQL seeding from {@see InitialDataSeeder} (which
 * runs `shield:generate`) is NOT in scope here — we replicate the relevant
 * subset manually so Filament's policy gates let super_admin through.
 */
function seedFlagPermissionsForSuperAdmin(): void
{
    $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    foreach ([
        'view_any_document_flag',
        'view_document_flag',
        'create_document_flag',
        'update_document_flag',
        'delete_document_flag',
        'delete_any_document_flag',
        'restore_document_flag',
        'restore_any_document_flag',
        'force_delete_document_flag',
        'force_delete_any_document_flag',
        'replicate_document_flag',
        'reorder_document_flag',
        'resolve_document_flag',
    ] as $perm) {
        $p = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $superAdmin->givePermissionTo($p);
    }
}

function makeFlagDocument(array $overrides = []): Document
{
    return Document::factory()->create($overrides);
}

/* -------------------------------------------------------------------------
 |  1 — Migration shape
 * ------------------------------------------------------------------------- */

test('migration creates document_flags table with all expected columns', function () {
    expect(Schema::hasTable('document_flags'))->toBeTrue();

    foreach ([
        'id',
        'document_id',
        'repository_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'context',
        'flagged_by_user_id',
        'flagged_at',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_notes',
        'created_at',
        'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('document_flags', $col))
            ->toBeTrue("Missing column: {$col}");
    }
});

/* -------------------------------------------------------------------------
 |  2 — Model contract: fillable, casts, defaults
 * ------------------------------------------------------------------------- */

test('model exposes expected fillable, casts and default attributes', function () {
    $m = new DocumentFlag;

    foreach ([
        'document_id', 'repository_id', 'type', 'severity', 'status',
        'title', 'description', 'context', 'flagged_by_user_id',
        'flagged_at', 'resolved_by_user_id', 'resolved_at', 'resolution_notes',
    ] as $field) {
        expect($m->getFillable())->toContain($field);
    }

    expect($m->getCasts())->toHaveKeys(['context', 'flagged_at', 'resolved_at']);
    expect($m->getCasts()['context'])->toBe('array');
    expect($m->getCasts()['flagged_at'])->toBe('datetime');
});

/* -------------------------------------------------------------------------
 |  3 — repository_id is mirrored from the parent Document
 * ------------------------------------------------------------------------- */

test('creating a flag copies repository_id from the parent document', function () {
    $repo = Repository::factory()->create();
    $doc = makeFlagDocument(['repository_id' => $repo->id]);

    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id]);

    expect($flag->repository_id)->toBe($repo->id);
});

/* -------------------------------------------------------------------------
 |  4 — Document::flags() returns newest-first
 * ------------------------------------------------------------------------- */

test('Document::flags() returns flags newest-first', function () {
    $doc = makeFlagDocument();

    $oldest = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'flagged_at' => now()->subDays(10),
    ]);
    $middle = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'flagged_at' => now()->subDays(5),
    ]);
    $newest = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'flagged_at' => now()->subDay(),
    ]);

    $ordered = $doc->flags()->get();
    expect($ordered->pluck('id')->all())->toBe([$newest->id, $middle->id, $oldest->id]);
});

/* -------------------------------------------------------------------------
 |  5 — Document::openFlags() filters to open + acknowledged
 * ------------------------------------------------------------------------- */

test('Document::openFlags() returns only open and acknowledged flags', function () {
    $doc = makeFlagDocument();

    $open = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $ack = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'acknowledged']);
    $resolved = DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id]);
    $dismissed = DocumentFlag::factory()->dismissed()->create(['document_id' => $doc->id]);

    $openIds = $doc->openFlags()->pluck('id')->all();

    expect($openIds)->toEqualCanonicalizing([$open->id, $ack->id]);
    expect($openIds)->not->toContain($resolved->id);
    expect($openIds)->not->toContain($dismissed->id);
});

/* -------------------------------------------------------------------------
 |  6 — markResolved() sets all resolution fields + writes an audit row
 * ------------------------------------------------------------------------- */

test('markResolved() sets status, resolved_by, resolved_at, notes — and emits an audit', function () {
    config(['audit.console' => true]); // owen-it skips console by default

    $doc = makeFlagDocument();
    $flag = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'status' => 'open',
    ]);

    $user = User::factory()->create();
    $user->repositories()->attach($doc->repository_id);

    Audit::query()->delete();

    $flag->markResolved($user, 'fixed in batch 17');
    $flag->refresh();

    expect($flag->status)->toBe('resolved');
    expect($flag->resolved_by_user_id)->toBe($user->id);
    expect($flag->resolved_at)->not->toBeNull();
    expect($flag->resolution_notes)->toBe('fixed in batch 17');

    $audits = Audit::where('auditable_type', (new DocumentFlag)->getMorphClass())
        ->where('auditable_id', $flag->id)
        ->get();

    expect($audits)->not->toBeEmpty();
});

/* -------------------------------------------------------------------------
 |  7 — markDismissed() and markAcknowledged()
 * ------------------------------------------------------------------------- */

test('markDismissed() and markAcknowledged() update status correctly', function () {
    $doc = makeFlagDocument();
    $user = User::factory()->create();

    $flag1 = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $flag1->markDismissed($user, 'false positive');
    $flag1->refresh();
    expect($flag1->status)->toBe('dismissed');
    expect($flag1->resolution_notes)->toBe('false positive');

    $flag2 = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $flag2->markAcknowledged($user);
    $flag2->refresh();
    expect($flag2->status)->toBe('acknowledged');
    // Acknowledged ≠ resolved — these stay null.
    expect($flag2->resolved_at)->toBeNull();
    expect($flag2->resolved_by_user_id)->toBeNull();

    // Idempotent: marking an already-closed flag does not slide it backwards
    // into "open" / "acknowledged".
    $flag1->markAcknowledged($user);
    $flag1->refresh();
    expect($flag1->status)->toBe('dismissed');
});

/* -------------------------------------------------------------------------
 |  8 — scopes: open, closed, ofType, ofSeverity
 * ------------------------------------------------------------------------- */

test('query scopes filter as advertised', function () {
    $doc = makeFlagDocument();

    DocumentFlag::factory()->count(2)->create(['document_id' => $doc->id, 'status' => 'open', 'type' => 'needs_review', 'severity' => 'warning']);
    DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'acknowledged', 'type' => 'damaged', 'severity' => 'critical']);
    DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id, 'type' => 'needs_review', 'severity' => 'info']);
    DocumentFlag::factory()->dismissed()->create(['document_id' => $doc->id, 'type' => 'other', 'severity' => 'info']);

    expect(DocumentFlag::query()->open()->count())->toBe(3);
    expect(DocumentFlag::query()->closed()->count())->toBe(2);
    expect(DocumentFlag::query()->ofType('needs_review')->count())->toBe(3);
    expect(DocumentFlag::query()->ofType(['needs_review', 'damaged'])->count())->toBe(4);
    expect(DocumentFlag::query()->ofSeverity('critical')->count())->toBe(1);
    expect(DocumentFlag::query()->ofSeverity(['info', 'critical'])->count())->toBe(3);
});

/* -------------------------------------------------------------------------
 |  9 — multi-tenant isolation
 * ------------------------------------------------------------------------- */

test('multi-tenant: editor in repo A cannot see flags on repo B documents', function () {
    ensureFlagsRolesExist();

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $docA = makeFlagDocument(['repository_id' => $repoA->id]);
    $docB = makeFlagDocument(['repository_id' => $repoB->id]);

    $flagA = DocumentFlag::factory()->create(['document_id' => $docA->id]);
    $flagB = DocumentFlag::factory()->create(['document_id' => $docB->id]);

    $user = User::factory()->create(['default_repository_id' => $repoA->id]);
    $user->assignRole('editor');
    $user->repositories()->attach($repoA->id);
    $this->actingAs($user);

    $visibleIds = DocumentFlag::query()->pluck('id')->all();
    expect($visibleIds)->toContain($flagA->id);
    expect($visibleIds)->not->toContain($flagB->id);
});

/* -------------------------------------------------------------------------
 |  10 — Filament RelationManager renders (Livewire)
 * ------------------------------------------------------------------------- */

test('Filament FlagsRelationManager renders the flags timeline on the document page', function () {
    ensureFlagsRolesExist();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $doc = makeFlagDocument();
    $flag = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'title' => 'Needs eyeballs from a notary expert',
        'type' => 'needs_review',
    ]);

    Livewire::test(FlagsRelationManager::class, [
        'ownerRecord' => $doc,
        'pageClass' => EditDocument::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$flag]);
});

/* -------------------------------------------------------------------------
 |  11 — Standalone DocumentFlagResource index page renders
 * ------------------------------------------------------------------------- */

test('DocumentFlagResource index page renders with open flags', function () {
    ensureFlagsRolesExist();
    seedFlagPermissionsForSuperAdmin();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $doc = makeFlagDocument();
    $openFlag = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $resolvedFlag = DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id]);

    Livewire::test(ListDocumentFlags::class)
        ->assertSuccessful()
        // The default filter is `open`, so only the open one should show.
        ->assertCanSeeTableRecords([$openFlag])
        ->assertCanNotSeeTableRecords([$resolvedFlag]);
});

/* -------------------------------------------------------------------------
 |  12 — Severity filter narrows the table
 * ------------------------------------------------------------------------- */

test('severity filter on DocumentFlagResource works', function () {
    ensureFlagsRolesExist();
    seedFlagPermissionsForSuperAdmin();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $doc = makeFlagDocument();
    $critical = DocumentFlag::factory()->critical()->create(['document_id' => $doc->id, 'status' => 'open']);
    $warning = DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'status' => 'open',
        'severity' => 'warning',
    ]);

    Livewire::test(ListDocumentFlags::class)
        ->filterTable('severity', ['critical'])
        ->assertCanSeeTableRecords([$critical])
        ->assertCanNotSeeTableRecords([$warning]);
});

/* -------------------------------------------------------------------------
 |  13 — Default filter is "open"
 * ------------------------------------------------------------------------- */

test('DocumentFlagResource default status filter is "open"', function () {
    // Reflection on the resource — the filter declaration sets ->default('open').
    // The behavioural assertion lives in test #11; this one nails the
    // declaration so a regression that changes the default value is caught
    // explicitly, regardless of whatever side effects exist in the index page.
    ensureFlagsRolesExist();
    seedFlagPermissionsForSuperAdmin();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $doc = makeFlagDocument();
    DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id]);
    $open = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);

    Livewire::test(ListDocumentFlags::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCountTableRecords(1);
});

/* -------------------------------------------------------------------------
 |  14 — Cascade delete removes flags when the parent is force-deleted
 * ------------------------------------------------------------------------- */

test('deleting a document cascades and removes its flags', function () {
    $doc = makeFlagDocument();
    DocumentFlag::factory()->count(3)->create(['document_id' => $doc->id]);

    expect(DocumentFlag::where('document_id', $doc->id)->count())->toBe(3);

    // forceDelete to actually trigger the FK cascade (Document uses SoftDeletes).
    $doc->forceDelete();

    expect(DocumentFlag::where('document_id', $doc->id)->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 |  15 — owen-it audit row on create
 * ------------------------------------------------------------------------- */

test('creating a flag writes an owen-it Audit row', function () {
    config(['audit.console' => true]); // owen-it skips console by default

    $doc = makeFlagDocument();
    Audit::query()->delete();

    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id]);

    $audits = Audit::where('auditable_type', (new DocumentFlag)->getMorphClass())
        ->where('auditable_id', $flag->id)
        ->where('event', 'created')
        ->get();

    expect($audits)->not->toBeEmpty();
});

/* -------------------------------------------------------------------------
 |  16 — Dashboard stat reports correct open/critical counts
 * ------------------------------------------------------------------------- */

test('StatsOverviewWidget reports open flag counts (total + critical)', function () {
    ensureFlagsRolesExist();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    Cache::flush(); // widget caches per-user; start clean

    $doc = makeFlagDocument();
    DocumentFlag::factory()->count(3)->create(['document_id' => $doc->id, 'status' => 'open', 'severity' => 'warning']);
    DocumentFlag::factory()->count(2)->critical()->create(['document_id' => $doc->id, 'status' => 'open']);
    DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id]);

    $widget = new StatsOverviewWidget;
    $reflectMethod = new ReflectionMethod($widget, 'getStats');
    $reflectMethod->setAccessible(true);
    /** @var array<int, Stat> $stats */
    $stats = $reflectMethod->invoke($widget);

    // Locate the "Open flags" stat by its label.
    $openFlagsStat = null;
    foreach ($stats as $s) {
        if ($s->getLabel() === 'Open flags') {
            $openFlagsStat = $s;

            break;
        }
    }
    expect($openFlagsStat)->not->toBeNull();
    // "5" total = 3 warning + 2 critical (the resolved one doesn't count).
    expect($openFlagsStat->getValue())->toBe('5');
    expect($openFlagsStat->getDescription())->toContain('2 critical');
    expect($openFlagsStat->getColor())->toBe('danger');
});

/* -------------------------------------------------------------------------
 |  17 — Document::toSearchableArray includes flag tokens
 * ------------------------------------------------------------------------- */

test('toSearchableArray() includes flag:<type> tokens for open flags only', function () {
    $doc = makeFlagDocument();

    DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'type' => 'needs_review',
        'status' => 'open',
    ]);
    DocumentFlag::factory()->create([
        'document_id' => $doc->id,
        'type' => 'duplicate_suspect',
        'status' => 'acknowledged', // still "open" for our purposes
    ]);
    DocumentFlag::factory()->resolved()->create([
        'document_id' => $doc->id,
        'type' => 'damaged',
    ]);

    $doc->refresh();
    $arr = $doc->toSearchableArray();

    expect($arr)->toHaveKey('flag_tokens');
    expect($arr['flag_tokens'])->toContain('flag:needs_review');
    expect($arr['flag_tokens'])->toContain('flag:duplicate_suspect');
    // Resolved flag must NOT pollute the search index — that's the whole
    // reason we drop closed flags from the tokens list.
    expect($arr['flag_tokens'])->not->toContain('flag:damaged');
});

/* -------------------------------------------------------------------------
 |  18 — Relationship typing — HasMany of DocumentFlag
 * ------------------------------------------------------------------------- */

test('Document::flags() returns a HasMany of DocumentFlag', function () {
    $doc = makeFlagDocument();
    $rel = $doc->flags();

    expect($rel)->toBeInstanceOf(HasMany::class);
    expect($rel->getRelated())->toBeInstanceOf(DocumentFlag::class);
});

/* -------------------------------------------------------------------------
 |  19 — Standalone resource navigation metadata
 * ------------------------------------------------------------------------- */

test('DocumentFlagResource navigation is wired up under Operations sort=85', function () {
    expect(DocumentFlagResource::getNavigationGroup())->toBe('Operations');
    expect(DocumentFlagResource::getNavigationSort())->toBe(85);
    expect(DocumentFlagResource::getNavigationIcon())->toBe('heroicon-o-flag');
});

/* -------------------------------------------------------------------------
 |  20 — Vocabulary helpers expose stable option lists
 * ------------------------------------------------------------------------- */

test('vocabulary helpers expose stable type / severity / status options', function () {
    $types = FlagsRelationManager::typeOptions();
    foreach (DocumentFlag::TYPES as $t) {
        expect($types)->toHaveKey($t);
    }
    expect(FlagsRelationManager::severityOptions())->toEqualCanonicalizing([
        'info' => 'Info',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ]);
    expect(FlagsRelationManager::statusOptions())->toEqualCanonicalizing([
        'open' => 'Open',
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ]);
});
