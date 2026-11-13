<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

/*
 * Security audit 2026-11-13, lead 3: the account e-mail is where a password
 * reset link goes, so changing it must ask for the current password. The
 * panel profile page does; Fortify's PUT /user/profile-information did not,
 * and nothing in the application uses that endpoint.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('does not change the e-mail through Fortify without the current password', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $user->assignRole('editor');

    $this->actingAs($user)->put('/user/profile-information', [
        'name' => $user->name,
        'email' => 'attacker@example.test',
    ]);

    expect($user->fresh()->email)->toBe('owner@example.test');
});

it('still asks for the current password on the panel profile page', function () {
    /** @var User $user */
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $user->assignRole('editor');

    $this->actingAs($user);

    livewire(EditProfile::class)
        ->fillForm(['email' => 'attacker@example.test'])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect($user->fresh()->email)->toBe('owner@example.test');
});
