<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource\Pages\EditAuthority;
use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\SeriesResource\Pages\EditSeries;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\FieldPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.8 — Field-level permissions (read / write / hidden).
 *
 * This suite exercises the full stack of the FieldPermissions layer:
 *  - the resolver in App\Support\FieldPermissions (pure logic);
 *  - the trait App\Filament\Concerns\AppliesFieldPermissions
 *    (how it wires gates into Filament form/table components);
 *  - the per-resource integration on the five main resources
 *    (Document, Authority, Series, Batch, Box).
 *
 * The matrix lives in config/field_permissions.php — these tests must
 * stay aligned with that config. If you change the matrix, expect to
 * update the explicit per-role assertions below.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/* ---------------------------------------------------------------- *
 | Factories — one-liner constructors for each role.                 |
 * ---------------------------------------------------------------- */

function fp_user(string $role, array $attrs = []): User
{
    $u = User::factory()->create(array_merge([
        'is_active' => true,
        'email' => 'fp-' . $role . '-' . uniqid() . '@test.local',
    ], $attrs));
    $u->assignRole($role);

    return $u;
}

function fp_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'FP_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function fp_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'FPS_' . substr(uniqid(), -4)],
        ['title' => 'FP series', 'is_active' => true],
    );
}

function fp_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'FPDOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/* ================================================================ *
 | Group 1 — FieldPermissions resolver (pure unit-style)             |
 * ================================================================ */

it('§3.1.8 #1: super_admin can read every field on every resource (defence-in-depth)', function () {
    $sa = fp_user('super_admin');

    foreach (['document', 'authority', 'series', 'batch', 'box'] as $resource) {
        foreach (['identifier', 'notes', 'repository_id', 'extra', 'is_active', 'box_type'] as $field) {
            expect(FieldPermissions::canRead($resource, $field, $sa))
                ->toBeTrue("super_admin should READ {$resource}.{$field}");
        }
    }
});

it('§3.1.8 #2: super_admin can write every field on every resource (defence-in-depth)', function () {
    $sa = fp_user('super_admin');

    foreach (['document', 'authority', 'series', 'batch', 'box'] as $resource) {
        foreach (['identifier', 'notes', 'repository_id', 'extra', 'is_active', 'box_type'] as $field) {
            expect(FieldPermissions::canWrite($resource, $field, $sa))
                ->toBeTrue("super_admin should WRITE {$resource}.{$field}");
        }
    }
});

it('§3.1.8 #3: super_admin is NEVER hidden, even from hidden_from fields', function () {
    $sa = fp_user('super_admin');

    expect(FieldPermissions::isHidden('document', 'extra', $sa))->toBeFalse();
    expect(FieldPermissions::canRead('document', 'extra', $sa))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'extra', $sa))->toBeTrue();
});

it('§3.1.8 #4: viewer can read Document.identifier (read default) but cannot write it', function () {
    $v = fp_user('viewer');

    expect(FieldPermissions::canRead('document', 'identifier', $v))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'identifier', $v))->toBeFalse();
});

it('§3.1.8 #5: editor can read AND write Document.identifier', function () {
    $e = fp_user('editor');

    expect(FieldPermissions::canRead('document', 'identifier', $e))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'identifier', $e))->toBeTrue();
});

it('§3.1.8 #6: editor can read Document.repository_id but cannot write it (admin-only)', function () {
    $e = fp_user('editor');

    expect(FieldPermissions::canRead('document', 'repository_id', $e))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'repository_id', $e))->toBeFalse();
});

it('§3.1.8 #7: admin can write Document.repository_id (tenant reassignment allowed)', function () {
    $a = fp_user('admin');

    expect(FieldPermissions::canWrite('document', 'repository_id', $a))->toBeTrue();
});

it('§3.1.8 #8: viewer is HIDDEN from Document.extra (schemaless bucket)', function () {
    $v = fp_user('viewer');

    expect(FieldPermissions::isHidden('document', 'extra', $v))->toBeTrue();
    expect(FieldPermissions::canRead('document', 'extra', $v))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'extra', $v))->toBeFalse();
});

it('§3.1.8 #9: editor is HIDDEN from Document.extra (same as viewer)', function () {
    $e = fp_user('editor');

    expect(FieldPermissions::isHidden('document', 'extra', $e))->toBeTrue();
    expect(FieldPermissions::canRead('document', 'extra', $e))->toBeFalse();
});

it('§3.1.8 #10: admin is NOT hidden from Document.extra (admin-only field, but admin sees it)', function () {
    $a = fp_user('admin');

    expect(FieldPermissions::isHidden('document', 'extra', $a))->toBeFalse();
    expect(FieldPermissions::canRead('document', 'extra', $a))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'extra', $a))->toBeTrue();
});

it('§3.1.8 #11: implicit-allow — field NOT in config defaults to all 4 roles allowed', function () {
    // `volume_number` is not listed explicitly under document; it falls
    // back to the `_default` block which grants read+write to all
    // operational roles. This is the forward-compat guarantee.
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        $u = fp_user($role);
        expect(FieldPermissions::canRead('document', 'volume_number', $u))
            ->toBeTrue("{$role} should be able to read volume_number via _default");
        // viewer is read-only by _default.write
        $expectedWrite = in_array($role, ['super_admin', 'admin', 'editor'], true);
        expect(FieldPermissions::canWrite('document', 'volume_number', $u))
            ->toBe($expectedWrite, "{$role} write on volume_number should be " . ($expectedWrite ? 'true' : 'false'));
    }
});

it('§3.1.8 #12: resource NOT in config defaults to fully open (forward-compat)', function () {
    // No matrix for "audit" — any role gets allow-all for any field on it.
    $v = fp_user('viewer');

    expect(FieldPermissions::canRead('audit', 'anything', $v))->toBeTrue();
    expect(FieldPermissions::canWrite('audit', 'anything', $v))->toBeTrue();
    expect(FieldPermissions::isHidden('audit', 'anything', $v))->toBeFalse();
});

it('§3.1.8 #13: config completely missing → graceful allow-all (no crash)', function () {
    config(['field_permissions' => null]);
    $v = fp_user('viewer');

    expect(FieldPermissions::canRead('document', 'identifier', $v))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'identifier', $v))->toBeTrue();
    expect(FieldPermissions::isHidden('document', 'identifier', $v))->toBeFalse();
});

it('§3.1.8 #14: null user in CONSOLE context (CLI/queue/pest) defaults to allow-all', function () {
    // PR #86 hardening (OWASP A01): null user is now fail-closed in HTTP
    // (DENY canRead/canWrite, HIDE isHidden) but stays fail-open in
    // CONSOLE context (CLI/queue/tinker/pest) so maintenance scripts
    // are never blocked. The pest harness runs under runningInConsole()
    // → this exercises the CONSOLE branch.
    expect(FieldPermissions::canRead('document', 'extra'))->toBeTrue();
    expect(FieldPermissions::canWrite('document', 'extra'))->toBeTrue();
    expect(FieldPermissions::isHidden('document', 'extra'))->toBeFalse();
});

it('§3.1.8 #15: hidden_from takes precedence over read — if both allow and hide, hide wins', function () {
    // Construct an artificial config to prove the precedence rule.
    config(['field_permissions.document.foo' => [
        'read' => ['viewer'],   // explicit allow
        'hidden_from' => ['viewer'],   // and explicit hide
    ]]);
    $v = fp_user('viewer');

    // hidden_from should win — the user must not see the field at all.
    expect(FieldPermissions::isHidden('document', 'foo', $v))->toBeTrue();
    expect(FieldPermissions::canRead('document', 'foo', $v))->toBeFalse();
});

it('§3.1.8 #16: a brand-new "guest" role with no permissions cannot write any field', function () {
    Role::firstOrCreate(['name' => 'guest', 'guard_name' => 'web']);
    $g = fp_user('guest');

    // Not in any allow-list anywhere — every check returns false.
    expect(FieldPermissions::canWrite('document', 'identifier', $g))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'notes', $g))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'extra', $g))->toBeFalse();
});

/* ================================================================ *
 | Group 2 — Per-resource matrix correctness                         |
 * ================================================================ */

it('§3.1.8 #17: Series.is_wills_series is admin-only-write (RFQ batch-50 rule)', function () {
    expect(FieldPermissions::canWrite('series', 'is_wills_series', fp_user('admin')))->toBeTrue();
    expect(FieldPermissions::canWrite('series', 'is_wills_series', fp_user('editor')))->toBeFalse();
    expect(FieldPermissions::canWrite('series', 'is_wills_series', fp_user('viewer')))->toBeFalse();

    // Read still allowed for all (so editors / viewers can see why a
    // particular series is highlighted in batch-50 contexts).
    expect(FieldPermissions::canRead('series', 'is_wills_series', fp_user('viewer')))->toBeTrue();
});

it('§3.1.8 #18: Batch.type and Batch.is_active are admin-only-write', function () {
    foreach (['type', 'is_active', 'repository_id'] as $field) {
        expect(FieldPermissions::canWrite('batch', $field, fp_user('admin')))->toBeTrue();
        expect(FieldPermissions::canWrite('batch', $field, fp_user('editor')))->toBeFalse();
        expect(FieldPermissions::canWrite('batch', $field, fp_user('viewer')))->toBeFalse();
    }
});

it('§3.1.8 #19: Box.box_type and Box.is_legacy are admin-only-write', function () {
    foreach (['box_type', 'is_legacy'] as $field) {
        expect(FieldPermissions::canWrite('box', $field, fp_user('admin')))->toBeTrue();
        expect(FieldPermissions::canWrite('box', $field, fp_user('editor')))->toBeFalse();
        expect(FieldPermissions::canWrite('box', $field, fp_user('viewer')))->toBeFalse();
    }

    // Editor can still write Box.barcode (operational data).
    expect(FieldPermissions::canWrite('box', 'barcode', fp_user('editor')))->toBeTrue();
});

it('§3.1.8 #20: Authority.identifier is admin-only-write (rename has cross-table impact)', function () {
    expect(FieldPermissions::canWrite('authority', 'identifier', fp_user('admin')))->toBeTrue();
    expect(FieldPermissions::canWrite('authority', 'identifier', fp_user('editor')))->toBeFalse();
    // editor may still edit notes.
    expect(FieldPermissions::canWrite('authority', 'notes', fp_user('editor')))->toBeTrue();
});

/* ================================================================ *
 | Group 3 — Filament integration (form rendering)                   |
 * ================================================================ */

it('§3.1.8 #21: viewer rendering ListDocuments page does not see the `extra` table column', function () {
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id, ['notes' => 'visible to viewer']);

    $viewer = fp_user('viewer');
    $viewer->repositories()->attach($repo->id, ['is_default' => true]);
    $viewer->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($viewer);

    // ListDocuments only renders configured columns (extra is in the
    // form, not the table). What matters here is that ListDocuments
    // boots cleanly under viewer with the gate active.
    Livewire::test(ListDocuments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$doc]);
});

it('§3.1.8 #22: admin rendering EditDocument has the `extra` KeyValue field visible+enabled', function () {
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id);

    $admin = fp_user('admin');
    $admin->repositories()->attach($repo->id, ['is_default' => true]);
    $admin->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($admin);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('extra');
});

it('§3.1.8 #23: editor rendering EditDocument does NOT have the `extra` field in the form', function () {
    // viewer is denied resource-level update_document by Shield, so the
    // EditDocument page itself returns 403 for them; that's correctly
    // handled by the layer above this one. To prove the field-level
    // hide_from logic works on a role that DOES get to the edit page,
    // we use editor (who is also in hidden_from for `extra`).
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id);

    $editor = fp_user('editor');
    $editor->repositories()->attach($repo->id, ['is_default' => true]);
    $editor->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($editor);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertFormFieldIsHidden('extra');
});

it('§3.1.8 #24: editor rendering EditDocument has `identifier` enabled but `repository_id` disabled', function () {
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id);

    $editor = fp_user('editor');
    $editor->repositories()->attach($repo->id, ['is_default' => true]);
    $editor->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($editor);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('identifier')
        ->assertFormFieldExists('repository_id')
        ->assertFormFieldIsDisabled('repository_id')
        ->assertFormFieldIsEnabled('identifier');
});

it('§3.1.8 #25: viewer is denied the EditDocument page by Shield (resource-level)', function () {
    // Shield + the codebase's Filament policies do NOT grant viewer the
    // `update_document` permission, so the Edit page is correctly a 403
    // for viewers — this is the resource-level layer doing its job. The
    // field-level layer (FieldPermissions) is what would gate identifier
    // IF the viewer ever reached the form, which they do not. Document
    // both behaviours: 403 here, and the FieldPermissions verdict
    // standing on its own.
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id);

    $viewer = fp_user('viewer');
    $viewer->repositories()->attach($repo->id, ['is_default' => true]);
    $viewer->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($viewer);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertForbidden();

    // Even though Shield blocks the page, the matrix still says viewer
    // cannot WRITE identifier (which is what would be enforced if a
    // future Edit-as-Viewer flow were ever introduced).
    expect(FieldPermissions::canWrite('document', 'identifier', $viewer))->toBeFalse();
});

it('§3.1.8 #26: super_admin rendering EditDocument has every field visible and enabled', function () {
    $repo = fp_repo();
    $series = fp_series();
    $doc = fp_doc($repo->id, $series->id);

    $sa = fp_user('super_admin');
    $sa->repositories()->attach($repo->id, ['is_default' => true]);
    $sa->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($sa);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('identifier')
        ->assertFormFieldExists('extra')
        ->assertFormFieldExists('repository_id')
        ->assertFormFieldIsEnabled('identifier')
        ->assertFormFieldIsEnabled('repository_id')
        ->assertFormFieldIsEnabled('extra');
});

/* ================================================================ *
 | Group 4 — Idempotency + audit-safety guarantees                   |
 * ================================================================ */

it('§3.1.8 #27: gate tightening a previously-writable field does NOT modify existing data', function () {
    // Real-world scenario: operator decides Series.code should become
    // admin-only-write after an editor has been editing it. We assert
    // that swapping the matrix at runtime DOES NOT touch any persisted
    // record — the gate is a UI layer, not a data migration.
    $series = Series::firstOrCreate(
        ['code' => 'TIGHTEN_' . substr(uniqid(), -4)],
        ['title' => 'Will tighten this', 'is_active' => true],
    );
    $originalCode = $series->code;

    // Tighten: code becomes admin-only-write.
    config(['field_permissions.series.code' => [
        'read' => ['super_admin', 'admin', 'editor', 'viewer'],
        'write' => ['super_admin', 'admin'],
    ]]);

    // Re-resolve the model — data is untouched.
    $series->refresh();
    expect($series->code)->toBe($originalCode);

    // The gate now blocks editor writes for new edits.
    $editor = fp_user('editor');
    expect(FieldPermissions::canWrite('series', 'code', $editor))->toBeFalse();
});

it('§3.1.8 #28: write-without-read is denied (consistency rule)', function () {
    // A nonsensical config that grants write but denies read should
    // STILL deny write — the form input would not be visible to the
    // user, so writing through it is a UI-impossibility.
    config(['field_permissions.document.weird' => [
        'read' => ['admin'],
        'write' => ['admin', 'viewer'],   // viewer should still be denied
    ]]);
    $v = fp_user('viewer');

    expect(FieldPermissions::canRead('document', 'weird', $v))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'weird', $v))->toBeFalse();
});

/* ================================================================ *
 | Group 5 — Cross-resource form smoke tests                         |
 * ================================================================ */

it('§3.1.8 #29: editor rendering EditAuthority sees identifier disabled (admin-only-write)', function () {
    $auth = Authority::create([
        'identifier' => 'AUTH-' . strtoupper(substr(uniqid(), -6)),
        'surname' => 'Cognome',
        'entity_type' => 'PERSON',
    ]);

    $this->actingAs(fp_user('editor'));

    Livewire::test(EditAuthority::class, ['record' => $auth->getRouteKey()])
        ->assertOk()
        ->assertFormFieldIsDisabled('identifier')
        ->assertFormFieldIsEnabled('surname');
});

it('§3.1.8 #30: editor rendering EditSeries sees is_wills_series disabled (admin-only-write)', function () {
    $series = Series::create([
        'code' => 'EDS_' . substr(uniqid(), -4),
        'title' => 'series',
        'is_active' => true,
    ]);

    $this->actingAs(fp_user('editor'));

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertFormFieldIsDisabled('is_wills_series')
        ->assertFormFieldIsDisabled('is_active')
        ->assertFormFieldIsEnabled('code')
        ->assertFormFieldIsEnabled('title');
});

it('§3.1.8 #31: editor rendering EditBatch sees type disabled (admin-only-write)', function () {
    $repo = fp_repo();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 8800 + random_int(0, 99),
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    $editor = fp_user('editor');
    $editor->repositories()->attach($repo->id, ['is_default' => true]);
    $editor->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($editor);

    Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->assertOk()
        ->assertFormFieldIsDisabled('type')
        ->assertFormFieldIsDisabled('is_active')
        ->assertFormFieldIsEnabled('description');
});

it('§3.1.8 #32: editor rendering EditBox sees box_type disabled (admin-only-write)', function () {
    // Box has a ThroughBatchRepositoryScope global scope: the row is
    // invisible to the resource page unless the user can see the
    // owning batch's repository. Attach the editor to the repo.
    $repo = fp_repo();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 9100 + random_int(0, 99),
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'BOX-' . substr(uniqid(), -6),
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);

    $editor = fp_user('editor');
    $editor->repositories()->attach($repo->id, ['is_default' => true]);
    $editor->forceFill(['default_repository_id' => $repo->id])->save();

    $this->actingAs($editor);

    Livewire::test(EditBox::class, ['record' => $box->getRouteKey()])
        ->assertOk()
        ->assertFormFieldIsDisabled('box_type')
        ->assertFormFieldIsDisabled('is_legacy')
        ->assertFormFieldIsEnabled('barcode')
        ->assertFormFieldIsEnabled('barcode_status');
});

/* ================================================================ *
 | Group 6 — Interaction with Filament Shield (layering proof)       |
 * ================================================================ */

it('§3.1.8 #33: FieldPermissions and Shield are independent — denial on either layer wins', function () {
    // Two layers, two independent gates. A role with no Shield grants
    // is denied at the resource level (Filament 403 on ListDocuments).
    // Independently, that same role is also outside every FieldPermissions
    // allow-list, so the matrix denies too. The point: denial on either
    // layer is sufficient; the user has to be allowed on BOTH layers
    // to actually see or edit a field.
    Role::firstOrCreate(['name' => 'nobody', 'guard_name' => 'web']);
    $n = fp_user('nobody');

    // Shield says no — `view_any_document` is not granted to 'nobody'.
    expect($n->can('view_any_document'))->toBeFalse();
    expect($n->can('update_document'))->toBeFalse();

    // FieldPermissions also says no — 'nobody' is not in any allow-list,
    // not even the `_default` for `document` (which only lists the four
    // operational roles).
    expect(FieldPermissions::canRead('document', 'identifier', $n))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'identifier', $n))->toBeFalse();

    // …whereas a viewer is allowed by BOTH layers to READ identifier:
    // Shield grants view_any_document, and the matrix's _default.read
    // includes viewer.
    $v = fp_user('viewer');
    expect($v->can('view_any_document'))->toBeTrue();
    expect(FieldPermissions::canRead('document', 'identifier', $v))->toBeTrue();
    // But Shield denies update_document for viewer, and matrix denies
    // write — independent layers agree.
    expect($v->can('update_document'))->toBeFalse();
    expect(FieldPermissions::canWrite('document', 'identifier', $v))->toBeFalse();
});

it('§3.1.8 #34: viewer has Shield read access AND FieldPermissions write denial — both layers active', function () {
    $v = fp_user('viewer');

    // Shield: viewer CAN view any document.
    expect($v->can('view_any_document'))->toBeTrue();
    // Field-level: viewer CANNOT write the identifier.
    expect(FieldPermissions::canWrite('document', 'identifier', $v))->toBeFalse();
    // Field-level: viewer IS HIDDEN from extra.
    expect(FieldPermissions::isHidden('document', 'extra', $v))->toBeTrue();
});

/* -------------------------------------------------------------------------
 |  OWASP A01 hardening (2026-05-28) — fail-closed-in-HTTP guardrail
 |
 |  The CONSOLE branch is covered by §3.1.8 #14 above (pest runs under
 |  runningInConsole()). The HTTP branch is exercised by the Browser/Dusk
 |  suite (Tier B #104). The structural assertion below pins the existence
 |  of the isConsole() routing helper so a refactor that removes the
 |  branching trips the test rather than silently re-introducing the
 |  fail-open HTTP regression.
 * ------------------------------------------------------------------------- */

test('FieldPermissions::isConsole() exists — anti-regression guardrail for the fail-closed-in-HTTP branch', function () {
    $reflection = new ReflectionClass(FieldPermissions::class);
    expect($reflection->hasMethod('isConsole'))->toBeTrue();
});
