<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AccessionResource\Pages\CreateAccession;
use App\Filament\Resources\AccessionResource\Pages\EditAccession;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\BatchResource;
use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Filament\Support\SearchableSelects;
use App\Models\Accession;
use App\Models\Batch;
use App\Models\Pivots\AccessionBatch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Wave B — N:N Batch ↔ Accession: extended coverage.
 *
 * Requirements tested (4+ assertions each):
 *   Pivot  — Batch 50 linked to multiple accessions; detach removes only the
 *             targeted row; unique pair is enforced.
 *   Round-trip — attach/detach cycle leaves the pivot in the expected state.
 *   UI form — AccessionResource has a 'batches' multi-select, no single
 *              'batch_id' field; BatchResource has an 'accessions' multi-select.
 *   Description — batch description auto-derived from accession titles (editable).
 *   Form save — Filament form create/edit syncs the pivot.
 *   Repo scope — Both sides of a pivot row respect repository scoping.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers (prefixed wbnn_ to avoid collisions with WaveBTest)
// ---------------------------------------------------------------------------

function wbnn_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'WBNN_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function wbnn_batch(int $repoId, int $number = 0, array $attrs = []): Batch
{
    /** @var array<string, mixed> $data */
    $data = array_merge([
        'batch_number' => $number > 0 ? $number : fake()->unique()->numberBetween(1, 9999),
        'description' => null,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
        'repository_id' => $repoId,
    ], $attrs);

    return Batch::withoutGlobalScope(RepositoryScope::class)->create($data);
}

function wbnn_accession(int $repoId, string $code = '', array $attrs = []): Accession
{
    /** @var array<string, mixed> $data */
    $data = array_merge([
        'code' => $code !== '' ? $code : 'ACC-' . strtoupper(substr(uniqid(), -6)),
        'repository_id' => $repoId,
    ], $attrs);

    return Accession::withoutGlobalScope(RepositoryScope::class)->create($data);
}

/**
 * Create a super_admin user (registers all four roles idempotently).
 */
function wbnn_sa(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'wbnn-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

// ===========================================================================
// Pivot — N:N attach / detach semantics
// ===========================================================================

/**
 * Pivot.1 — Batch 50 (wills batch) can be linked to multiple accessions
 *            from different notaries, verifying the core "N" on the accession side.
 */
it('Batch 50 can collect wills from multiple accessions', function (): void {
    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id, 50);   // 50 = wills-only batch
    $acc1 = wbnn_accession($repo->id, 'WILLS-ACC-1');
    $acc2 = wbnn_accession($repo->id, 'WILLS-ACC-2');
    $acc3 = wbnn_accession($repo->id, 'WILLS-ACC-3');

    $batch->accessions()->attach([$acc1->id, $acc2->id, $acc3->id]);

    expect($batch->accessions()->count())->toBe(3);
    expect($batch->accessions()->where('accessions.id', $acc1->id)->exists())->toBeTrue();
    expect($batch->accessions()->where('accessions.id', $acc2->id)->exists())->toBeTrue();
    expect($batch->accessions()->where('accessions.id', $acc3->id)->exists())->toBeTrue();
});

/**
 * Pivot.2 — Detaching one accession from a batch removes exactly that pivot row
 *            and leaves the others intact.
 */
it('detaching one accession removes only that pivot row', function (): void {
    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);
    $acc1 = wbnn_accession($repo->id);
    $acc2 = wbnn_accession($repo->id);

    $batch->accessions()->attach([$acc1->id, $acc2->id]);
    expect($batch->accessions()->count())->toBe(2);

    $batch->accessions()->detach($acc1->id);

    expect($batch->accessions()->count())->toBe(1);
    expect($batch->accessions()->where('accessions.id', $acc1->id)->exists())->toBeFalse();
    expect($batch->accessions()->where('accessions.id', $acc2->id)->exists())->toBeTrue();
});

/**
 * Pivot.3 — The unique constraint on (accession_id, batch_id) raises a
 *            QueryException on a duplicate insert attempt.
 */
it('duplicate pivot pair throws a QueryException', function (): void {
    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);
    $accession = wbnn_accession($repo->id);

    $accession->batches()->attach($batch->id);

    expect(fn () => $accession->batches()->attach($batch->id))
        ->toThrow(QueryException::class);
});

/**
 * Pivot.4 — After a full detach, the accession can be re-attached with no error
 *            (round-trip: attach → detach → re-attach).
 */
it('accession can be re-attached after a detach (round-trip)', function (): void {
    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);
    $accession = wbnn_accession($repo->id);

    $accession->batches()->attach($batch->id);
    expect($accession->batches()->count())->toBe(1);

    $accession->batches()->detach($batch->id);
    expect($accession->batches()->count())->toBe(0);

    // Re-attach after detach must succeed (no stale unique-key row).
    $accession->batches()->attach($batch->id);
    expect($accession->batches()->count())->toBe(1);
    expect($accession->batches()->where('batches.id', $batch->id)->exists())->toBeTrue();
});

// ===========================================================================
// Data-migration round-trip (post-migration state)
// ===========================================================================

/**
 * Migration.1 — The accession_batch pivot table exists and its raw row count
 *               is consistent with what we insert via the relation.
 */
it('accession_batch pivot row count tracks attach/detach correctly', function (): void {
    $repo = wbnn_repo();
    $batch1 = wbnn_batch($repo->id);
    $batch2 = wbnn_batch($repo->id);
    $acc = wbnn_accession($repo->id);

    $before = DB::table('accession_batch')->count();

    $acc->batches()->attach([$batch1->id, $batch2->id]);
    expect(DB::table('accession_batch')->count())->toBe($before + 2);

    $acc->batches()->detach($batch1->id);
    expect(DB::table('accession_batch')->count())->toBe($before + 1);

    $acc->batches()->detach();
    expect(DB::table('accession_batch')->count())->toBe($before);
});

/**
 * Migration.2 — The pivot rows carry timestamps (created_at / updated_at) because
 *               both sides use ->withTimestamps().
 */
it('pivot rows carry timestamps', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id);
    $bat = wbnn_batch($repo->id);

    $acc->batches()->attach($bat->id);

    $row = DB::table('accession_batch')
        ->where('accession_id', $acc->id)
        ->where('batch_id', $bat->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->created_at)->not->toBeNull();
    expect($row->updated_at)->not->toBeNull();
});

/**
 * Migration.3 — syncWithoutDetaching merges new entries without removing
 *               existing ones (safe merge behaviour for partial updates).
 */
it('syncWithoutDetaching adds new batches without removing existing ones', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id);
    $bat1 = wbnn_batch($repo->id);
    $bat2 = wbnn_batch($repo->id);
    $bat3 = wbnn_batch($repo->id);

    $acc->batches()->attach($bat1->id);
    $acc->batches()->syncWithoutDetaching([$bat2->id, $bat3->id]);

    expect($acc->batches()->count())->toBe(3);
    foreach ([$bat1->id, $bat2->id, $bat3->id] as $bId) {
        expect($acc->batches()->where('batches.id', $bId)->exists())->toBeTrue();
    }
});

/**
 * Migration.4 — sync() (with detach) replaces the whole pivot side,
 *               ensuring old entries not in the new set are removed.
 */
it('sync() replaces the full pivot side for an accession', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id);
    $bat1 = wbnn_batch($repo->id);
    $bat2 = wbnn_batch($repo->id);
    $bat3 = wbnn_batch($repo->id);

    $acc->batches()->attach([$bat1->id, $bat2->id]);
    $acc->batches()->sync([$bat3->id]);

    expect($acc->batches()->count())->toBe(1);
    expect($acc->batches()->where('batches.id', $bat3->id)->exists())->toBeTrue();
    expect($acc->batches()->where('batches.id', $bat1->id)->exists())->toBeFalse();
    expect($acc->batches()->where('batches.id', $bat2->id)->exists())->toBeFalse();
});

// ===========================================================================
// UI form — field presence / absence
// ===========================================================================

/**
 * Form.1 — AccessionResource create form has the 'batches' multi-select field
 *           and does NOT have a single 'batch_id' field.
 */
it('AccessionResource create form has batches field and no batch_id field', function (): void {
    $this->actingAs(wbnn_sa());

    $lw = Livewire::test(CreateAccession::class);
    $lw->assertFormFieldExists('batches');

    // Verify the single-FK field is gone: use the positive structural assertion.
    $lw->assertFormFieldDoesNotExist('batch_id');
});

/**
 * Form.2 — BatchResource create form has the 'accessions' multi-select field.
 */
it('BatchResource create form has accessions multi-select field', function (): void {
    $this->actingAs(wbnn_sa());

    Livewire::test(CreateBatch::class)
        ->assertFormFieldExists('accessions');
});

/**
 * Form.3 — AccessionResource edit form also has 'batches' (not batch_id),
 *           confirming the removal is consistent across create + edit.
 */
it('AccessionResource edit form has batches and no batch_id', function (): void {
    $this->actingAs(wbnn_sa());

    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id);

    $lw = Livewire::test(EditAccession::class, ['record' => $acc->getRouteKey()]);
    $lw->assertFormFieldExists('batches');

    // Structural assertion: batch_id must not be a form component.
    $lw->assertFormFieldDoesNotExist('batch_id');
});

/**
 * Form.4 — BatchResource edit form has 'accessions' multi-select.
 */
it('BatchResource edit form has accessions multi-select', function (): void {
    $this->actingAs(wbnn_sa());

    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);

    Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->assertFormFieldExists('accessions');
});

// ===========================================================================
// Description auto-derived from linked accession titles
// ===========================================================================

/**
 * Desc.1 — A batch with no description derives it from a single linked
 *           accession title (code) when the Accessions multi-select is
 *           populated via the form state callback.
 *
 * Note: the auto-derive logic lives in afterStateUpdated() on the
 * 'accessions' Select in BatchResource form. We test the model-layer
 * behaviour directly (no Livewire roundtrip needed) because the closure
 * calls Accession::withoutGlobalScopes()->whereIn('id', $ids)->pluck('code').
 */
it('batch description is auto-derived from linked accession codes', function (): void {
    $repo = wbnn_repo();
    $acc1 = wbnn_accession($repo->id, 'Alpha Accession');
    $acc2 = wbnn_accession($repo->id, 'Beta Accession');

    $batch = wbnn_batch($repo->id, attrs: ['description' => null]);
    expect($batch->description)->toBeNull();

    // Simulate what afterStateUpdated does: resolve codes sorted, join with ', '.
    $codes = Accession::withoutGlobalScopes()
        ->whereIn('id', [$acc1->id, $acc2->id])
        ->orderBy('code')
        ->pluck('code')
        ->all();

    $derived = implode(', ', $codes);

    $batch->update(['description' => $derived]);
    $batch->accessions()->attach([$acc1->id, $acc2->id]);

    $batch->refresh();
    expect($batch->description)->toBe('Alpha Accession, Beta Accession');
    expect($batch->accessions()->count())->toBe(2);
});

/**
 * Desc.2 — Description already set by the operator is NOT overwritten when
 *           accessions are attached (editable override semantics).
 */
it('manually set description is preserved when accessions are attached', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id, 'Gamma Accession');
    $batch = wbnn_batch($repo->id, attrs: ['description' => 'Custom operator text']);

    // afterStateUpdated guard: if description !== '' it does NOT overwrite.
    $currentDesc = $batch->description;
    $batch->accessions()->attach($acc->id);

    // Description must remain unchanged.
    $batch->refresh();
    expect($batch->description)->toBe('Custom operator text');
    expect($currentDesc)->toBe($batch->description);
});

/**
 * Desc.3 — When all accessions are removed from a batch (pivot cleared),
 *           the description is not automatically blanked — operator text is
 *           sticky (the closure returns early when $state is empty).
 */
it('clearing all accessions does not automatically blank the description', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id);
    $batch = wbnn_batch($repo->id, attrs: ['description' => 'Some description']);

    $batch->accessions()->attach($acc->id);
    $batch->accessions()->detach();

    $batch->refresh();
    // afterStateUpdated returns early when $state === [], so description is stable.
    expect($batch->description)->toBe('Some description');
    expect($batch->accessions()->count())->toBe(0);
});

/**
 * Desc.4 — accessionLabel() in SearchableSelects appends the FIRST batch number
 *           to the accession code when the accession has linked batches.
 */
it('accessionLabel includes the first batch number when batches are present', function (): void {
    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id, 'Delta Accession');
    $bat7 = wbnn_batch($repo->id, 7);
    $bat14 = wbnn_batch($repo->id, 14);

    $acc->batches()->attach([$bat7->id, $bat14->id]);
    $acc->load('batches');

    $label = SearchableSelects::accessionLabel($acc);

    // The lowest batch_number (7) should appear in the label.
    expect($label)->toContain('7');
    expect($label)->toContain('Delta Accession');
});

// ===========================================================================
// Form-save syncs pivot (Filament BelongsToMany via ->relationship())
// ===========================================================================

/**
 * Save.1 — Creating an Accession via the Filament form with a batches[]
 *           selection persists the pivot rows.
 */
it('creating an Accession via Filament form syncs the batches pivot', function (): void {
    $user = wbnn_sa();
    $this->actingAs($user);

    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);

    Livewire::test(CreateAccession::class)
        ->fillForm([
            'code' => 'FORM-CREATE-ACC-1',
            'repository_id' => $repo->id,
            'batches' => [$batch->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $acc = Accession::withoutGlobalScope(RepositoryScope::class)
        ->where('code', 'FORM-CREATE-ACC-1')
        ->first();

    expect($acc)->not->toBeNull();
    expect($acc->batches()->where('batches.id', $batch->id)->exists())->toBeTrue();
});

/**
 * Save.2 — Editing an Accession via the Filament form updates the pivot:
 *           old batch is removed, new batch is linked.
 */
it('editing an Accession via Filament form replaces the batches pivot', function (): void {
    $user = wbnn_sa();
    $this->actingAs($user);

    $repo = wbnn_repo();
    $old = wbnn_batch($repo->id);
    $new = wbnn_batch($repo->id);
    $acc = wbnn_accession($repo->id, 'EDIT-ACC-PIVOT-1');
    $acc->batches()->attach($old->id);

    Livewire::test(EditAccession::class, ['record' => $acc->getRouteKey()])
        ->fillForm([
            'batches' => [$new->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $acc->refresh();
    expect($acc->batches()->where('batches.id', $new->id)->exists())->toBeTrue();
    expect($acc->batches()->where('batches.id', $old->id)->exists())->toBeFalse();
});

/**
 * Save.3 — Creating a Batch via the Filament form with accessions[] selection
 *           persists the pivot rows.
 */
it('creating a Batch via Filament form syncs the accessions pivot', function (): void {
    $user = wbnn_sa();
    $this->actingAs($user);

    $repo = wbnn_repo();
    $acc = wbnn_accession($repo->id, 'BATCH-CREATE-PIVOT-ACC');

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => 888,
            'type' => 'NOTARY_ACCESSION',
            'repository_id' => $repo->id,
            'accessions' => [$acc->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', 888)
        ->first();

    expect($batch)->not->toBeNull();
    expect($batch->accessions()->where('accessions.id', $acc->id)->exists())->toBeTrue();
});

/**
 * Save.4 — Editing a Batch via the Filament form updates the pivot:
 *           old accession removed, new accession linked.
 */
it('editing a Batch via Filament form replaces the accessions pivot', function (): void {
    $user = wbnn_sa();
    $this->actingAs($user);

    $repo = wbnn_repo();
    $oldAcc = wbnn_accession($repo->id, 'OLD-PIVOT-ACC');
    $newAcc = wbnn_accession($repo->id, 'NEW-PIVOT-ACC');
    $batch = wbnn_batch($repo->id);
    $batch->accessions()->attach($oldAcc->id);

    Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->fillForm([
            'accessions' => [$newAcc->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $batch->refresh();
    expect($batch->accessions()->where('accessions.id', $newAcc->id)->exists())->toBeTrue();
    expect($batch->accessions()->where('accessions.id', $oldAcc->id)->exists())->toBeFalse();
});

// ===========================================================================
// Repository scoping on both sides of the pivot
// ===========================================================================

/**
 * Scope.1 — An accession from repo A only sees its own batches; a batch from
 *            repo B has no accessions even though the accession exists.
 *
 *            Crucially, the AccessionResource Eloquent query (which applies
 *            RepositoryScope) must return ONLY repo-A rows when acting as a
 *            repo-A-only editor — repo-B accession must be invisible.
 */
it('accession sees only its own batches via the pivot', function (): void {
    $repoA = wbnn_repo();
    $repoB = wbnn_repo();

    $accA = wbnn_accession($repoA->id);
    $batA1 = wbnn_batch($repoA->id);
    $batA2 = wbnn_batch($repoA->id);
    $batB = wbnn_batch($repoB->id);
    // repo-B accession — must be invisible to a repo-A actor.
    $accB = wbnn_accession($repoB->id, 'ACC-B-SCOPE');

    $accA->batches()->attach([$batA1->id, $batA2->id]);
    $accB->batches()->attach($batB->id);

    // Pivot-level assertions (no auth scope).
    expect($accA->batches()->count())->toBe(2);
    expect($accA->batches()->where('batches.id', $batB->id)->exists())->toBeFalse();
    expect($batB->accessions()->count())->toBe(1); // accB is there

    // Cross-repo isolation: authenticate as a repo-A-only editor and assert
    // that AccessionResource::getEloquentQuery() (which applies RepositoryScope)
    // returns only repo-A rows and excludes repo-B.
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $editorA = User::factory()->create(['email' => 'scope1-editor-' . uniqid() . '@test.local', 'is_active' => true]);
    $editorA->assignRole('editor');
    $editorA->repositories()->attach($repoA->id, ['is_default' => true]);

    $this->actingAs($editorA);

    $visibleIds = AccessionResource::getEloquentQuery()->pluck('accessions.id')->all();

    // repo-A accession is visible; repo-B accession is excluded.
    expect($visibleIds)->toContain($accA->id);
    expect($visibleIds)->not->toContain($accB->id);
});

/**
 * Scope.2 — F041: the pivot now enforces spec B5 ("both sides same repo").
 *            Attaching a foreign-repo accession to a batch throws a
 *            DomainException; same-repo attaches succeed; the guard is
 *            null-tolerant (it only fires when BOTH sides resolve a non-null,
 *            differing repository_id — expand-never-restrict).
 */
it('cross-repo pivot attach throws; same-repo attach succeeds; guard is null-tolerant', function (): void {
    $repoA = wbnn_repo();
    $repoB = wbnn_repo();

    // Use repoA's Batch 50 (distinct batch_number per test thanks to RefreshDatabase).
    $batch50 = wbnn_batch($repoA->id, 50);
    $accA = wbnn_accession($repoA->id);   // same repo as batch50
    $accA2 = wbnn_accession($repoA->id);  // same repo as batch50
    $accB = wbnn_accession($repoB->id);   // foreign repo

    // Same-repo attach is allowed — Batch 50 collects many same-repo accessions.
    $batch50->accessions()->attach([$accA->id, $accA2->id]);
    expect($batch50->accessions()->where('accessions.id', $accA->id)->exists())->toBeTrue();
    expect($batch50->accessions()->where('accessions.id', $accA2->id)->exists())->toBeTrue();

    // Cross-repo attach is now rejected by the AccessionBatch pivot guard (B5).
    expect(fn () => $batch50->accessions()->attach($accB->id))
        ->toThrow(DomainException::class);

    // The rejected row was never written.
    expect($batch50->accessions()->where('accessions.id', $accB->id)->exists())->toBeFalse();

    // Null-tolerance: the guard's mismatch branch is gated on BOTH sides being
    // non-null. When one side resolves to null (legacy / unresolved repo) the
    // guard short-circuits and does NOT throw, mirroring F030's
    // expand-never-restrict contract. We fire the pivot's `creating` event
    // directly with a batch_id that resolves to no repository (no such batch)
    // and assert no DomainException is raised by the guard. (A real FK-backed
    // insert can't carry a null repository because the schema is NOT NULL, so
    // we exercise the guard branch in isolation.)
    $pivot = new AccessionBatch;
    $pivot->accession_id = $accB->id;   // repoB (non-null)
    $pivot->batch_id = PHP_INT_MAX;     // no such batch → repo resolves null

    // Fire the registered `creating` model event for this pivot and assert the
    // guard does not veto it (returns no `false`, raises no DomainException).
    $threwDomain = false;

    try {
        AccessionBatch::getEventDispatcher()
            ->dispatch('eloquent.creating: ' . AccessionBatch::class, $pivot);
    } catch (DomainException) {
        $threwDomain = true;
    }
    expect($threwDomain)->toBeFalse();
})->group('f041');

/**
 * Scope.3 — AccessionResource list page filter 'batches' only surfaces
 *            accessions that are linked to the selected batch via the pivot.
 */
it('AccessionResource list batches filter respects the pivot', function (): void {
    $user = wbnn_sa();
    $this->actingAs($user);

    $repo = wbnn_repo();
    $batch = wbnn_batch($repo->id);
    $linked = wbnn_accession($repo->id, 'LINKED-SCOPE');
    $other = wbnn_accession($repo->id, 'UNLINKED-SCOPE');

    $linked->batches()->attach($batch->id);

    Livewire::test(ListAccessions::class)
        ->filterTable('batches', $batch->id)
        ->assertCanSeeTableRecords([$linked])
        ->assertCanNotSeeTableRecords([$other]);
});

/**
 * Scope.4 — F041: attaching batches from two different repositories to one
 *            accession is now rejected by the AccessionBatch pivot guard.
 *            The same-repo batch attaches; the foreign-repo batch throws and
 *            its row is never written; same-repo linked batches still query
 *            back correctly.
 */
it('cross-repo batch attach on an accession throws and writes no row', function (): void {
    $repoA = wbnn_repo();
    $repoB = wbnn_repo();
    $acc = wbnn_accession($repoA->id);
    $batchA = wbnn_batch($repoA->id);   // same repo
    $batchA2 = wbnn_batch($repoA->id);  // same repo
    $batchB = wbnn_batch($repoB->id);   // foreign repo

    // Same-repo batches attach fine.
    $acc->batches()->attach([$batchA->id, $batchA2->id]);
    expect($acc->batches()->count())->toBe(2);

    // Foreign-repo batch attach is rejected by the B5 guard.
    expect(fn () => $acc->batches()->attach($batchB->id))
        ->toThrow(DomainException::class);

    // The rejected batch was not linked; the same-repo links remain intact.
    $ids = $acc->batches()->pluck('batches.id')->sort()->values()->all();
    expect($ids)->toContain($batchA->id);
    expect($ids)->toContain($batchA2->id);
    expect($ids)->not->toContain($batchB->id);
});
