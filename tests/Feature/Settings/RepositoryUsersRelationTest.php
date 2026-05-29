<?php

declare(strict_types=1);

use App\Filament\Resources\RepositoryResource\Pages\EditRepository;
use App\Filament\Resources\RepositoryResource\RelationManagers\UsersRelationManager;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Task 7 — UsersRelationManager on RepositoryResource.
 *
 * Covers attach and detach of users through the BelongsToMany pivot relation
 * (repository_user table, is_default pivot column).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function rolesExist_rurm(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsSuperAdmin_rurm(): User
{
    rolesExist_rurm();
    $u = User::factory()->create([
        'email' => 'rurm-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

it('attaches and detaches a user to a repository', function () {
    $this->actingAs(actAsSuperAdmin_rurm());

    $repo = Repository::factory()->create();
    $user = User::factory()->create();

    // Attach via relation manager
    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $repo,
        'pageClass' => EditRepository::class,
    ])
        ->callTableAction('attach', data: ['recordId' => $user->id])
        ->assertHasNoTableActionErrors();

    expect($repo->users()->whereKey($user->id)->exists())->toBeTrue();

    // Detach via relation manager
    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $repo,
        'pageClass' => EditRepository::class,
    ])
        ->callTableAction('detach', $user)
        ->assertHasNoTableActionErrors();

    expect($repo->users()->whereKey($user->id)->exists())->toBeFalse();
});

it('relation manager renders the user table', function () {
    $this->actingAs(actAsSuperAdmin_rurm());

    $repo = Repository::factory()->create();
    $user = User::factory()->create(['name' => 'Target User']);
    $repo->users()->attach($user->id, ['is_default' => false]);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $repo,
        'pageClass' => EditRepository::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$user]);
});
