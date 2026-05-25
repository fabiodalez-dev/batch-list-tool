<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Models\Accession;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(DatabaseTransactions::class);

function rolesExist_acc(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsAdmin_acc(): User
{
    rolesExist_acc();
    $u = User::factory()->create([
        'email'     => 'acc-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');
    return $u;
}

function makeRepo_acc(string $prefix = 'AC'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function makeAcc(int $repoId, array $attrs = []): Accession
{
    return Accession::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'code'          => 'ACC-' . strtoupper(substr(uniqid(), -6)),
        'repository_id' => $repoId,
    ], $attrs));
}

/* 41. list renders */
test('AccessionResource list page renders', function () {
    $this->actingAs(actAsAdmin_acc());

    $repo = makeRepo_acc();
    $acc  = makeAcc($repo->id);

    Livewire::test(ListAccessions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$acc]);
});

/* 42. create persists with code/source field */
test('AccessionResource create persists with code and notes', function () {
    $repo = makeRepo_acc();
    $acc  = makeAcc($repo->id, [
        'notes'          => 'Source: Notary office Borg, 2026',
        'accession_date' => '2026-01-15',
    ]);

    expect(Accession::withoutGlobalScope(RepositoryScope::class)
        ->where('code', $acc->code)->exists())->toBeTrue();
    expect($acc->notes)->toContain('Notary office Borg');
});

/* 43. Accession appears on Document detail (link via accession_id) */
test('A Document points back to its Accession via accession_id FK', function () {
    $repo = makeRepo_acc();
    $series = Series::query()->first()
        ?? Series::create(['code' => 'AC-S', 'title' => 'AC series', 'is_active' => true]);
    $acc  = makeAcc($repo->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier'    => 'AC-DOC-' . uniqid(),
        'document_type' => 'TEST',
        'series_id'     => $series->id,
        'repository_id' => $repo->id,
        'accession_id'  => $acc->id,
    ]);

    expect($doc->accession_id)->toBe($acc->id);
    expect($doc->accession->is($acc))->toBeTrue();
    expect($acc->documents()->where('documents.id', $doc->id)->exists())->toBeTrue();
});

/* 44. Multi-tenant scope on Accession (uses BelongsToRepository trait) */
test('AccessionResource respects RepositoryScope for an editor', function () {
    rolesExist_acc();

    $rA = makeRepo_acc('A');
    $rB = makeRepo_acc('B');
    $aA = makeAcc($rA->id);
    $aB = makeAcc($rB->id);

    $editor = User::factory()->create([
        'email'                 => 'ac-editor+' . uniqid() . '@test.local',
        'is_active'             => true,
        'default_repository_id' => $rA->id,
    ]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($rA->id, ['is_default' => true]);

    $this->actingAs($editor);

    $ids = Accession::query()->whereIn('id', [$aA->id, $aB->id])->pluck('id')->all();
    expect($ids)->toContain($aA->id);
    expect($ids)->not->toContain($aB->id);
});
