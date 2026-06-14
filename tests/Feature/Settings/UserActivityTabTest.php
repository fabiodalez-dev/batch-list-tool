<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\ActivityRelationManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * Task 13 — Per-user "Activity" tab (read-only audit trail of actions BY the user).
 *
 * The key semantic distinction: `activityAudits()` = rows in `audits` where
 * `user_id = $user->id` (the ACTOR). This is NOT owen-it's built-in `audits()`
 * which returns audits OF the user record itself.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function rolesExist_uat(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsSuperAdmin_uat(): User
{
    rolesExist_uat();
    $u = User::factory()->create([
        'email' => 'uat-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

// -- Model relation ------------------------------------------------------------

it('exposes activityAudits() as a HasMany returning rows where user_id = actor', function () {
    $actor = User::factory()->create();

    Audit::create([
        'user_type' => $actor->getMorphClass(),
        'user_id' => $actor->id,
        'event' => 'login',
        'auditable_type' => $actor->getMorphClass(),
        'auditable_id' => $actor->id,
        'old_values' => [],
        'new_values' => [],
        'url' => '/x',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'tags' => null,
    ]);

    $rel = $actor->activityAudits();
    expect($rel)->toBeInstanceOf(HasMany::class);
    expect($actor->activityAudits()->where('event', 'login')->exists())->toBeTrue();
});

it('activityAudits does NOT return audits made TO the user by a different actor', function () {
    $admin = User::factory()->create();
    $subject = User::factory()->create();

    // Audit OF $subject, performed BY $admin (admin changed subject's record)
    Audit::create([
        'user_type' => $admin->getMorphClass(),
        'user_id' => $admin->id,
        'event' => 'updated',
        'auditable_type' => $subject->getMorphClass(),
        'auditable_id' => $subject->id,
        'old_values' => ['name' => 'Old'],
        'new_values' => ['name' => 'New'],
        'url' => '/admin/users/' . $subject->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'tags' => null,
    ]);

    // $subject did nothing themselves — their activityAudits should be empty
    expect($subject->activityAudits()->count())->toBe(0);
    // $admin is the actor — the audit appears in their activity
    expect($admin->activityAudits()->count())->toBe(1);
});

it('activityAudits returns multiple rows sorted newest first', function () {
    $actor = User::factory()->create();

    $older = Audit::create([
        'user_type' => $actor->getMorphClass(),
        'user_id' => $actor->id,
        'event' => 'created',
        'auditable_type' => $actor->getMorphClass(),
        'auditable_id' => $actor->id,
        'old_values' => [],
        'new_values' => [],
        'url' => '/a',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'tags' => null,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $newer = Audit::create([
        'user_type' => $actor->getMorphClass(),
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => $actor->getMorphClass(),
        'auditable_id' => $actor->id,
        'old_values' => [],
        'new_values' => [],
        'url' => '/b',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'tags' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = $actor->activityAudits()->pluck('id')->all();
    expect($ids[0])->toBe($newer->id);
    expect($ids[1])->toBe($older->id);
});

// -- Filament RelationManager --------------------------------------------------

it('ActivityRelationManager class exists with correct relationship property', function () {
    expect(class_exists(ActivityRelationManager::class))->toBeTrue();

    $rm = new ReflectionClass(ActivityRelationManager::class);
    $relProp = $rm->getProperty('relationship');
    expect($relProp->getValue())->toBe('activityAudits');
});

it('UserResource registers ActivityRelationManager in getRelations()', function () {
    expect(UserResource::getRelations())
        ->toContain(ActivityRelationManager::class);
});

it('ActivityRelationManager renders rows and is read-only (no create action)', function () {
    $admin = actAsSuperAdmin_uat();
    $this->actingAs($admin);

    $actor = User::factory()->create();

    Audit::create([
        'user_type' => $actor->getMorphClass(),
        'user_id' => $actor->id,
        'event' => 'updated',
        'auditable_type' => $actor->getMorphClass(),
        'auditable_id' => $actor->id,
        'old_values' => [],
        'new_values' => [],
        'url' => '/admin/test',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'phpunit',
        'tags' => null,
    ]);

    $livewire = Livewire::test(ActivityRelationManager::class, [
        'ownerRecord' => $actor,
        'pageClass' => EditUser::class,
    ]);

    $livewire->assertSuccessful()
        ->assertCountTableRecords(1);

    // Read-only: no create action in header
    $livewire->assertTableActionDoesNotExist('create');
});
