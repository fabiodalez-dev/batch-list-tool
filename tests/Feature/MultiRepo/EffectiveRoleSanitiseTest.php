<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F5 — effectiveRoleFor() must not trust an unknown / forged pivot role value;
 * it falls back to the user's global role instead of returning the raw string.
 */
it('ignores an unknown/forged pivot role and falls back to the global role', function (): void {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    $repo = Repository::factory()->create();
    $user->repositories()->attach($repo, ['role' => 'super_admin_FORGED']);

    expect($user->effectiveRoleFor($repo))->toBe('viewer');
});

it('still trusts a pivot role when it is a real defined role', function (): void {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    $repo = Repository::factory()->create();
    $user->repositories()->attach($repo, ['role' => 'editor']);

    expect($user->effectiveRoleFor($repo))->toBe('editor');
});
