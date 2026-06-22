<?php

declare(strict_types=1);

use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * NAF Feedback-1 comment #9 — `is_active` now has a real meaning: an inactive
 * batch stays on the Batches list (editable/reactivatable) but must NOT be
 * offered as a selectable parent when creating Boxes or Documents.
 *
 * The Box/Document batch picker is built by SearchableSelects::batch(), which
 * calls batchSearchResults(activeOnly: true). An already-selected inactive
 * batch must still resolve its label (so editing an existing record is fine).
 */
uses(RefreshDatabase::class);

function abs_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

it('hides inactive batches from the parent-batch picker but keeps them selectable elsewhere', function () {
    $repo = Repository::create(['code' => 'ABS', 'name' => 'Active Batch Select']);
    $u = abs_admin($repo->id);
    $this->actingAs($u);

    $active = Batch::create(['batch_number' => 11, 'type' => 'MAIN_COLLECTION', 'is_active' => true, 'repository_id' => $repo->id]);
    $inactive = Batch::create(['batch_number' => 12, 'type' => 'MAIN_COLLECTION', 'is_active' => false, 'repository_id' => $repo->id]);

    // Parent picker (Box/Document) — activeOnly: only the active batch appears.
    $picker = SearchableSelects::batchSearchResults('', activeOnly: true);
    expect($picker)->toHaveKey($active->id)
        ->and($picker)->not->toHaveKey($inactive->id);

    // Default (multi-select / Accession linking) is unaffected — both appear.
    $unfiltered = SearchableSelects::batchSearchResults('');
    expect($unfiltered)->toHaveKey($active->id)
        ->and($unfiltered)->toHaveKey($inactive->id);

    // The active() scope returns only active batches.
    expect(Batch::active()->pluck('id')->all())->toBe([$active->id]);
});
