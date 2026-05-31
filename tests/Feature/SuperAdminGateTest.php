<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

it('grants super_admin every ability via the Shield gate, even abilities with no permission row', function () {
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    $this->actingAs($u);

    // view_any_user is NOT seeded as a permission, yet super_admin must pass.
    expect($u->can('view_any_user'))->toBeTrue()
        ->and($u->can('create_user'))->toBeTrue()
        ->and($u->can('some_made_up_ability'))->toBeTrue()
        ->and(UserResource::canViewAny())->toBeTrue();
});

it('does NOT grant a viewer the super_admin bypass', function () {
    $u = User::factory()->create();
    $u->assignRole('viewer');
    $this->actingAs($u);

    expect($u->can('view_any_user'))->toBeFalse()
        ->and(UserResource::canViewAny())->toBeFalse();
});
