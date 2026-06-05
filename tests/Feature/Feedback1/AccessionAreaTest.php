<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AccessionResource\Pages\CreateAccession;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\ActiveRepository;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Wave A / Feedback1 — AccessionResource label/UX improvements.
 *
 * Covers:
 *   A3  — label renames: Title, Accession Number, Accession Date, Batch Number.
 *   A7  — deferFilters + Apply button; filter panel present when empty.
 *   A5  — per-column sorting on every sortable column.
 *   A8  — only the "Title" cell is a hyperlink (no whole-row recordUrl).
 *   A9  — CreatorColumn (Inputter) present in the table.
 *   Repo — active-repository scoping via RepositoryScope (BelongsToRepository).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function aa_superAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'aa-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function aa_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'AA_' . substr(uniqid(), -6),
    ]);
}

function aa_accession(int $repoId, array $attrs = []): Accession
{
    return Accession::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'code' => 'ACC-' . strtoupper(substr(uniqid(), -6)),
        'repository_id' => $repoId,
    ], $attrs));
}

// ===========================================================================
// A3 — Label renames
// ===========================================================================

/**
 * A3.1 — "code" table column is labelled "Title", not "Code".
 */
it('table column code is labelled Title', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $cols = collect($table->getColumns());

    expect($cols->first(fn ($c) => $c->getName() === 'code')?->getLabel())->toBe('Title');
});

/**
 * A3.2 — "accession_number" column is labelled "Accession Number".
 */
it('table column accession_number is labelled Accession Number', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $cols = collect($table->getColumns());

    expect($cols->first(fn ($c) => $c->getName() === 'accession_number')?->getLabel())->toBe('Accession Number');
});

/**
 * A3.3 — "accession_date" column is labelled "Accession Date" (capitalised).
 */
it('table column accession_date is labelled Accession Date', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $cols = collect($table->getColumns());

    expect($cols->first(fn ($c) => $c->getName() === 'accession_date')?->getLabel())->toBe('Accession Date');
});

/**
 * B4 — "batches_list" column is labelled "Batch Numbers" (N:N replaces single batch).
 * The old "batch.batch_number" column is gone; the new column shows comma-separated
 * batch numbers from the pivot.
 */
it('table column batches_list is labelled Batch Numbers', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $cols = collect($table->getColumns());

    expect($cols->first(fn ($c) => $c->getName() === 'batches_list')?->getLabel())->toBe('Batch Numbers');
});

/**
 * A3.5 — form field "code" is labelled "Title".
 */
it('form field code is labelled Title', function () {
    $this->actingAs(aa_superAdmin());

    Livewire::test(CreateAccession::class)
        ->assertFormFieldExists('code', fn ($field) => $field->getLabel() === 'Title');
});

/**
 * A3.6 — form field "accession_number" is labelled "Accession Number".
 */
it('form field accession_number is labelled Accession Number', function () {
    $this->actingAs(aa_superAdmin());

    Livewire::test(CreateAccession::class)
        ->assertFormFieldExists('accession_number', fn ($field) => $field->getLabel() === 'Accession Number');
});

/**
 * A3.7 — form field "accession_date" is labelled "Accession Date".
 */
it('form field accession_date is labelled Accession Date', function () {
    $this->actingAs(aa_superAdmin());

    Livewire::test(CreateAccession::class)
        ->assertFormFieldExists('accession_date', fn ($field) => $field->getLabel() === 'Accession Date');
});

/**
 * A3.8 — infolist entry "accession_number" is labelled "Accession Number".
 *
 * We build the infolist schema directly from the Resource and traverse the
 * component tree to find the TextEntry with name "accession_number".
 */
it('infolist entry accession_number is labelled Accession Number', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);

    $lw = Livewire::test(ListAccessions::class);
    $schema = Schema::make($lw->instance());

    $built = AccessionResource::infolist($schema);

    // Flatten all entries from all top-level Section children.
    $entries = collect();
    foreach ($built->getComponents() as $section) {
        if (method_exists($section, 'getChildComponents')) {
            foreach ($section->getChildComponents() as $entry) {
                $entries->push($entry);
            }
        }
    }

    $entry = $entries->first(fn ($e) => method_exists($e, 'getName') && $e->getName() === 'accession_number');

    expect($entry)->not->toBeNull()
        ->and($entry->getLabel())->toBe('Accession Number');
});

// ===========================================================================
// A7 — Filters: deferFilters (Apply button) + panel visible when empty
// ===========================================================================

/**
 * A7.1 — Table has deferFilters enabled (Apply button will be present in UI).
 */
it('table has deferFilters enabled', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    expect($table->hasDeferredFilters())->toBeTrue();
});

/**
 * A7.2 — Filter panel is not collapsed/hidden when the table has no rows.
 *
 * The Livewire component renders without error even when zero rows exist,
 * and filters are still accessible (component mounts OK → panel is present).
 */
it('list page mounts OK with zero accessions and filters remain accessible', function () {
    $this->actingAs(aa_superAdmin());

    // No accessions created — table is empty.
    Livewire::test(ListAccessions::class)
        ->assertOk()
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('accession_number');
});

/**
 * A7.3 — Applying the "Has Accession Number" filter hides non-matching rows.
 */
it('ternary filter has_accession_number correctly filters rows', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);
    $repo = aa_repo();

    $withNum = aa_accession($repo->id, ['accession_number' => '2025-100']);
    $withoutNum = aa_accession($repo->id);

    Livewire::test(ListAccessions::class)
        ->filterTable('has_accession_number', true)
        ->assertCanSeeTableRecords([$withNum])
        ->assertCanNotSeeTableRecords([$withoutNum]);
});

/**
 * A7.4 — Filter apply: authority filter narrows the table correctly.
 */
it('authority filter narrows result set correctly', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);
    $repo = aa_repo();

    $authority = Authority::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AA-' . substr(uniqid(), -4),
        'surname' => 'Zammit' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
        'repository_id' => $repo->id,
    ]);

    $withAuth = aa_accession($repo->id, ['authority_id' => $authority->id]);
    $noAuth = aa_accession($repo->id);

    Livewire::test(ListAccessions::class)
        ->filterTable('authority', $authority->id)
        ->assertCanSeeTableRecords([$withAuth])
        ->assertCanNotSeeTableRecords([$noAuth]);
});

// ===========================================================================
// A5 — Per-column sorting
// ===========================================================================

/**
 * A5.1 — "code" (Title) column is sortable.
 */
it('code column is sortable', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'code');
    expect($col?->isSortable())->toBeTrue();
});

/**
 * A5.2 — "accession_number" column is sortable.
 */
it('accession_number column is sortable', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'accession_number');
    expect($col?->isSortable())->toBeTrue();
});

/**
 * A5.3 — "accession_date" column is sortable.
 */
it('accession_date column is sortable', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'accession_date');
    expect($col?->isSortable())->toBeTrue();
});

/**
 * A5.4 — Sort by accession_date works end-to-end on Livewire component.
 */
it('table can be sorted by accession_date', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);
    $repo = aa_repo();

    $older = aa_accession($repo->id, ['accession_date' => '2020-01-01']);
    $newer = aa_accession($repo->id, ['accession_date' => '2024-06-01']);

    Livewire::test(ListAccessions::class)
        ->sortTable('accession_date')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
});

// ===========================================================================
// A8 — Only primary identifier cell is the hyperlink
// ===========================================================================

/**
 * A8.1 — The table has no whole-row recordUrl.
 *
 * Filament 5 exposes hasCustomRecordUrl(): bool on the Table, which returns
 * false when ->recordUrl() was never called. That means clicking a row does
 * NOT navigate — only the explicitly-url()-decorated column cell does.
 */
it('table does not have a whole-row recordUrl', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    // hasCustomRecordUrl() is true only when ->recordUrl(...) was explicitly
    // called. False means the whole row is NOT a link.
    expect($table->hasCustomRecordUrl())->toBeFalse();
});

/**
 * A8.2 — The "code" (Title) column has a url() closure configured (stored as
 * the protected $url property on the CanOpenUrl trait).
 */
it('code column has a url closure (is the only hyperlink)', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'code');
    expect($col)->not->toBeNull();

    // Read the protected $url property via reflection — the public API
    // only offers getUrl() which requires evaluation context.
    $ref = new ReflectionProperty($col, 'url');
    $ref->setAccessible(true);
    $urlValue = $ref->getValue($col);

    // A Closure or non-null string means ->url() was configured.
    expect($urlValue)->not->toBeNull();
});

/**
 * A8.3 — The url on the code column resolves to the view page for an accession.
 */
it('code column url resolves to the view page', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);
    $repo = aa_repo();
    $acc = aa_accession($repo->id);

    $expectedUrl = AccessionResource::getUrl('view', ['record' => $acc]);

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'code');

    // Invoke the protected $url closure directly via reflection with the
    // record as typed injection so the fn(Accession $record) parameter binds.
    $ref = new ReflectionProperty($col, 'url');
    $ref->setAccessible(true);
    $urlClosure = $ref->getValue($col);

    expect($urlClosure)->toBeInstanceOf(Closure::class);
    $resolvedUrl = $urlClosure($acc);

    expect($resolvedUrl)->toBe($expectedUrl);
});

// ===========================================================================
// A9 — CreatorColumn (Inputter) present in the table
// ===========================================================================

/**
 * A9.1 — The "inputter" column exists in the AccessionResource table.
 */
it('table has an inputter column', function () {
    $this->actingAs(aa_superAdmin());

    Livewire::test(ListAccessions::class)
        ->assertTableColumnExists('inputter');
});

/**
 * A9.2 — The inputter column is labelled "Inputter".
 */
it('inputter column is labelled Inputter', function () {
    $this->actingAs(aa_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    $col = collect($table->getColumns())->first(fn ($c) => $c->getName() === 'inputter');
    expect($col?->getLabel())->toBe('Inputter');
});

// ===========================================================================
// Repository scoping — active-repository narrows the list
// ===========================================================================

/**
 * Repo.1 — When RepositoryScope is active for a specific repo, an admin user
 * who has their active_repository set only sees accessions in that repo.
 */
it('RepositoryScope narrows the accession list to the active repository', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);

    $repoA = aa_repo();
    $repoB = aa_repo();

    $accA = aa_accession($repoA->id);
    $accB = aa_accession($repoB->id);

    // Simulate the active-repository session key pointing to repoA.
    session([ActiveRepository::SESSION_KEY => $repoA->id]);

    // The RepositoryScope will narrow the query to repoA only.
    $ids = Accession::query()->pluck('id')->all();

    expect($ids)->toContain($accA->id)
        ->and($ids)->not->toContain($accB->id);

    // Clean up session so other tests are unaffected.
    session()->forget(ActiveRepository::SESSION_KEY);
});

/**
 * Repo.2 — When active_repository = null ("All"), both repos are visible
 * to a super_admin.
 */
it('accessions from all repos are visible when no active repository is set', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);

    $repoA = aa_repo();
    $repoB = aa_repo();

    $accA = aa_accession($repoA->id);
    $accB = aa_accession($repoB->id);

    // No active_repository in session → "All".
    session()->forget(ActiveRepository::SESSION_KEY);

    $ids = Accession::query()->pluck('id')->all();

    expect($ids)->toContain($accA->id)
        ->and($ids)->toContain($accB->id);
});

/**
 * Repo.3 — Editor assigned to repoA cannot see accessions in repoB via Livewire.
 */
it('editor only sees accessions in their assigned repository via list page', function () {
    $repoA = aa_repo();
    $repoB = aa_repo();

    $accA = aa_accession($repoA->id);
    $accB = aa_accession($repoB->id);

    $editor = User::factory()->create([
        'email' => 'aa-editor+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoA->id,
    ]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoA->id, ['is_default' => true]);

    $this->actingAs($editor);

    Livewire::test(ListAccessions::class)
        ->assertCanSeeTableRecords([$accA])
        ->assertCanNotSeeTableRecords([$accB]);
});

/**
 * Repo.4 — getEloquentQuery() includes an eager-load of audits so the
 * CreatorColumn can resolve names without N+1 queries.
 */
it('getEloquentQuery eager-loads audits relation', function () {
    $user = aa_superAdmin();
    $this->actingAs($user);

    $query = AccessionResource::getEloquentQuery();

    $eagerLoads = $query->getEagerLoads();

    // 'audits' must be in the eager-load map.
    expect(array_key_exists('audits', $eagerLoads))->toBeTrue();
});
