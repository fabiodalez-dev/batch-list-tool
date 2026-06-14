<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\ExportSelectedAction;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\FieldPermissions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.4 — Field permissions enforced consistently across UI, JSON API,
 * and EXPORTS (CSV). A role with a field marked HIDDEN must not receive that
 * field's data in any export path — neither through the "Export selected" bulk
 * action nor through the "Export all filtered" header button.
 */
uses(RefreshDatabase::class);

/* -------------------------------------------------------------------------
 |  Local helpers (prefixed efp_ to avoid collisions with other test files)
 * ------------------------------------------------------------------------- */

function efp_seedPermissions(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $names = [];
    foreach (['document', 'authority', 'batch', 'box', 'series', 'repository', 'user', 'location', 'report', 'report_template', 'import_profile', 'accession', 'audit', 'document_flag', 'box_movement', 'volume', 'role'] as $r) {
        foreach (['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'force_delete', 'force_delete_any', 'restore', 'restore_any', 'replicate', 'reorder'] as $op) {
            $names[] = "{$op}_{$r}";
        }
    }
    $names[] = 'resolve_document_flag';

    foreach ($names as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $all = Permission::pluck('name')->all();

    Role::findByName('super_admin', 'web')->syncPermissions($all);
    Role::findByName('admin', 'web')->syncPermissions($all);

    $editorPerms = collect($all)->filter(fn ($p) => str_starts_with($p, 'view_')
        || str_starts_with($p, 'create_')
        || str_starts_with($p, 'update_')
        || str_starts_with($p, 'reorder_')
        || $p === 'resolve_document_flag')->all();
    Role::findByName('editor', 'web')->syncPermissions($editorPerms);

    $viewerPerms = collect($all)->filter(fn ($p) => str_starts_with($p, 'view_') && ! str_ends_with($p, '_user'))->all();
    Role::findByName('viewer', 'web')->syncPermissions($viewerPerms);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
}

function efp_user(string $role, ?Repository $repo = null): User
{
    $u = User::factory()->create([
        'is_active' => true,
        'email' => 'efp-' . $role . '-' . uniqid() . '@test.local',
        'default_repository_id' => $repo?->id,
    ]);
    $u->assignRole($role);
    if ($repo instanceof Repository) {
        $u->repositories()->attach($repo->id, ['is_default' => true]);
    }

    return $u;
}

function efp_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'EFP_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function efp_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'EFPS_' . substr(uniqid(), -4)],
        ['title' => 'EFP series', 'is_active' => true],
    );
}

function efp_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'EFPDOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/**
 * Invoke ListDocuments::exportToCsv() through a fully-booted Livewire
 * component and return the raw CSV string (BOM stripped).
 */
function efp_captureFilteredCsv(): string
{
    $component = Livewire::test(ListDocuments::class);
    /** @var ListDocuments $page */
    $page = $component->instance();

    ob_start();
    $page->exportToCsv()->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Invoke ExportSelectedAction::bulk() on a given EloquentCollection and
 * capture the streamed CSV body (BOM stripped).
 *
 * The action is a BulkAction — its closure receives the collection directly.
 * We reach into the registered action to call the internal perform() by
 * reflection so we don't need a full Livewire bulk-select cycle.
 */
function efp_captureSelectedCsv(EloquentCollection $records): string
{
    // ExportSelectedAction::perform() is private; invoke via reflection.
    $ref = new ReflectionClass(ExportSelectedAction::class);
    $method = $ref->getMethod('perform');

    ob_start();
    /** @var StreamedResponse $resp */
    $resp = $method->invoke(null, $records);
    $resp->sendContent();

    return ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
}

/**
 * Parse a CSV string into an array of associative rows keyed by the
 * header row. Returns [$headers, $rows] where $rows is array<int, array<string,string>>.
 *
 * @return array{0: list<string>, 1: list<array<string,string>>}
 */
function efp_parseCsv(string $csv): array
{
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $headers = fgetcsv($fh, escape: '\\') ?: [];
    $rows = [];
    while (($r = fgetcsv($fh, escape: '\\')) !== false) {
        $rows[] = array_combine($headers, $r);
    }
    fclose($fh);

    return [$headers, $rows];
}

/* -------------------------------------------------------------------------
 |  beforeEach
 * ------------------------------------------------------------------------- */

beforeEach(function () {
    efp_seedPermissions();
    Cache::flush();
    // Always flush the FieldPermissions override cache so config() changes
    // in individual tests take effect immediately.
    FieldPermissions::flushCache();
});

/* =========================================================================
 |  §3.1.4 #1 — Filtered export: viewer with notes HIDDEN does not get it
 * ========================================================================= */

test('§3.1.4 #1: viewer with notes hidden does NOT receive notes column in filtered CSV', function () {
    // Arrange — hide notes from viewer via config override (same mechanism
    // as FieldPermissionsTest #15; no DB override needed).
    config([
        'field_permissions.document.notes' => [
            'read' => ['super_admin', 'admin', 'editor'],
            'hidden_from' => ['viewer'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    $notesValue = 'SENSITIVE_NOTES_' . strtoupper(substr(uniqid(), -8));
    efp_doc($repo->id, $series->id, ['notes' => $notesValue]);

    $viewer = efp_user('viewer', $repo);
    $this->actingAs($viewer);

    // Confirm the resolver agrees the field is hidden for this user.
    expect(FieldPermissions::isHidden('document', 'notes', $viewer))->toBeTrue();

    // Act — drive the real export path.
    $csv = efp_captureFilteredCsv();

    [$headers, $rows] = efp_parseCsv($csv);

    // Assert — header must not contain "Notes".
    expect($headers)->not->toContain('Notes');

    // And the unique notes value must not appear anywhere in the raw CSV body.
    expect($csv)->not->toContain($notesValue);
});

/* =========================================================================
 |  §3.1.4 #2 — Filtered export: super_admin always gets notes
 * ========================================================================= */

test('§3.1.4 #2: super_admin always receives all columns (including notes) in filtered CSV', function () {
    // Even with a restrictive config, super_admin bypasses all gates.
    config([
        'field_permissions.document.notes' => [
            'read' => ['super_admin', 'admin', 'editor'],
            'hidden_from' => ['viewer'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    $notesValue = 'SUPER_NOTES_' . strtoupper(substr(uniqid(), -8));
    efp_doc($repo->id, $series->id, ['notes' => $notesValue]);

    $sa = efp_user('super_admin', $repo);
    $this->actingAs($sa);

    expect(FieldPermissions::isHidden('document', 'notes', $sa))->toBeFalse();
    expect(FieldPermissions::canRead('document', 'notes', $sa))->toBeTrue();

    $csv = efp_captureFilteredCsv();
    [$headers, $rows] = efp_parseCsv($csv);

    expect($headers)->toContain('Notes');
    expect($csv)->toContain($notesValue);
});

/* =========================================================================
 |  §3.1.4 #3 — Filtered export: disinfestation_date hidden from editor
 * ========================================================================= */

test('§3.1.4 #3: editor with disinfestation_date hidden does NOT receive that column', function () {
    // Use a different field to prove the mechanism is field-agnostic.
    config([
        'field_permissions.document.disinfestation_date' => [
            'read' => ['super_admin', 'admin', 'viewer'],
            'hidden_from' => ['editor'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    efp_doc($repo->id, $series->id, ['disinfestation_date' => '2026-01-15']);

    $editor = efp_user('editor', $repo);
    $this->actingAs($editor);

    expect(FieldPermissions::isHidden('document', 'disinfestation_date', $editor))->toBeTrue();

    $csv = efp_captureFilteredCsv();
    [$headers, $rows] = efp_parseCsv($csv);

    expect($headers)->not->toContain('Disinfestation date');
    expect($csv)->not->toContain('2026-01-15');
});

/* =========================================================================
 |  §3.1.4 #4 — Selected-rows export: viewer with notes HIDDEN
 * ========================================================================= */

test('§3.1.4 #4: viewer with notes hidden does NOT receive notes column in selected-rows CSV', function () {
    config([
        'field_permissions.document.notes' => [
            'read' => ['super_admin', 'admin', 'editor'],
            'hidden_from' => ['viewer'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    $notesValue = 'SELECTED_NOTES_' . strtoupper(substr(uniqid(), -8));
    $doc = efp_doc($repo->id, $series->id, ['notes' => $notesValue]);

    $viewer = efp_user('viewer', $repo);
    $this->actingAs($viewer);

    /** @var EloquentCollection<int,Document> $records */
    $records = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereKey($doc->id)
        ->get();

    $csv = efp_captureSelectedCsv($records);
    [$headers, $rows] = efp_parseCsv($csv);

    expect($headers)->not->toContain('Notes');
    expect($csv)->not->toContain($notesValue);
});

/* =========================================================================
 |  §3.1.4 #5 — Selected-rows export: super_admin keeps all columns
 * ========================================================================= */

test('§3.1.4 #5: super_admin receives all columns in selected-rows CSV', function () {
    config([
        'field_permissions.document.notes' => [
            'read' => ['super_admin', 'admin', 'editor'],
            'hidden_from' => ['viewer'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    $notesValue = 'SA_SELECTED_NOTES_' . strtoupper(substr(uniqid(), -8));
    $doc = efp_doc($repo->id, $series->id, ['notes' => $notesValue]);

    $sa = efp_user('super_admin', $repo);
    $this->actingAs($sa);

    /** @var EloquentCollection<int,Document> $records */
    $records = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereKey($doc->id)
        ->get();

    $csv = efp_captureSelectedCsv($records);
    [$headers, $rows] = efp_parseCsv($csv);

    expect($headers)->toContain('Notes');
    expect($csv)->toContain($notesValue);
});

/* =========================================================================
 |  §3.1.4 #6 — Both export paths produce identical column sets for same user
 * ========================================================================= */

test('§3.1.4 #6: filtered-CSV and selected-CSV produce the same column set for viewer with notes hidden', function () {
    config([
        'field_permissions.document.notes' => [
            'read' => ['super_admin', 'admin', 'editor'],
            'hidden_from' => ['viewer'],
        ],
    ]);
    FieldPermissions::flushCache();

    $repo = efp_repo();
    $series = efp_series();
    $doc = efp_doc($repo->id, $series->id, ['notes' => 'should-not-leak']);

    $viewer = efp_user('viewer', $repo);
    $this->actingAs($viewer);

    $filteredCsv = efp_captureFilteredCsv();
    [$filteredHeaders] = efp_parseCsv($filteredCsv);

    /** @var EloquentCollection<int,Document> $records */
    $records = Document::withoutGlobalScope(RepositoryScope::class)->whereKey($doc->id)->get();
    $selectedCsv = efp_captureSelectedCsv($records);
    [$selectedHeaders] = efp_parseCsv($selectedCsv);

    // Both must agree on which columns are present.
    expect($filteredHeaders)->toBe($selectedHeaders);

    // Neither must contain "Notes".
    expect($filteredHeaders)->not->toContain('Notes');
    expect($selectedHeaders)->not->toContain('Notes');
});
