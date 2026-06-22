<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Models\Accession;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * NAF Feedback-1 comment #10 — "Tried to add a new Batch 999 to check this out
 * but I am getting an error". Reproduces creating a brand-new batch and linking
 * an accession, asserting the happy path saves and that a cross-repository link
 * fails gracefully (validation message) rather than with an unhandled 500.
 */
uses(RefreshDatabase::class);

function cb999_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

it('creates Batch 999 with a same-repository accession linked', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Archive']);
    $u = cb999_admin($repo->id);
    $this->actingAs($u);

    $acc = Accession::create(['code' => 'ACC-1', 'repository_id' => $repo->id]);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => 999,
            'type' => 'NOTARY_ACCESSION',
            'repository_id' => $repo->id,
            'accessions' => [$acc->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::query()->where('batch_number', 999)->firstOrFail();
    expect($batch->accessions()->count())->toBe(1)
        ->and($batch->description)->toBe('ACC-1'); // auto-derived from accession code
});

it('rejects a cross-repository accession link with a readable form error, not a 500', function () {
    $repoA = Repository::create(['code' => 'NRA', 'name' => 'National Archive']);
    $repoB = Repository::create(['code' => 'MUS', 'name' => 'Museum']);
    $u = cb999_admin($repoA->id);
    $u->repositories()->syncWithoutDetaching([$repoA->id, $repoB->id]);
    $this->actingAs($u);

    // Accession lives in repo B, but the batch is being created in repo A.
    $foreign = Accession::create(['code' => 'ACC-B', 'repository_id' => $repoB->id]);

    Livewire::test(CreateBatch::class)
        ->fillForm([
            'batch_number' => 999,
            'type' => 'NOTARY_ACCESSION',
            'repository_id' => $repoA->id,
            'accessions' => [$foreign->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['accessions']);

    // Nothing was persisted — the guard stopped the save cleanly.
    expect(Batch::query()->where('batch_number', 999)->exists())->toBeFalse();
});
