<?php

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

it('resolves the per-repository role from the pivot', function () {
    $u = User::factory()->create();
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $u->repositories()->attach($a, ['role' => 'admin']);
    $u->repositories()->attach($b, ['role' => 'viewer']);

    expect($u->effectiveRoleFor($a))->toBe('admin')
        ->and($u->effectiveRoleFor($b))->toBe('viewer');
});

it('falls back to the global role when the pivot role is null', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $u->repositories()->attach($a, ['role' => null]);

    expect($u->effectiveRoleFor($a))->toBe('editor');
});

it('super_admin always resolves to super_admin', function () {
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    $a = Repository::factory()->create();
    $u->repositories()->attach($a, ['role' => 'viewer']);

    expect($u->effectiveRoleFor($a))->toBe('super_admin');
});
