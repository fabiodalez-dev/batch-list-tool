<?php

declare(strict_types=1);

use App\Models\Repository;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F6 — for a privileged user, ActiveRepository::set() must reject a
 * non-existent repository id (fail-closed to "All") instead of pinning a
 * phantom id that would surprise the user with an empty scope.
 */
it('ignores a non-existent repository id for a privileged user', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $active = resolve(ActiveRepository::class);
    expect($active->set(999999))->toBeNull();
    expect($active->id())->toBeNull();
});

it('accepts an existing repository id for a privileged user', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));
    $repo = Repository::factory()->create();

    $active = resolve(ActiveRepository::class);
    expect($active->set($repo->id))->toBe($repo->id);
    expect($active->id())->toBe($repo->id);
});
