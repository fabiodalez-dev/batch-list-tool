<?php

declare(strict_types=1);

use App\Models\UserTablePreference;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Column layout kept per user (client 2026-10-30, issue 8)
|--------------------------------------------------------------------------
| The feature tests call the Livewire method directly; this one goes through
| the column manager the user clicks, so the JS path is covered too.
*/

it('saves a column hidden from the column manager for the user (client 2026-10-30)', function () {
    $admin = bl_actor('super_admin');

    $page = bl_login($admin)->navigate('/admin/practices')->assertSee('Description');
    $page->click('[aria-label="Column manager"]')->wait(1)
        ->click('input.fi-checkbox-input[id$="-description"]')->wait(1)
        ->click('Apply columns')->wait(2)
        ->assertNoJavascriptErrors();

    $saved = UserTablePreference::query()->where('user_id', $admin->id)->sole();
    $description = collect($saved->columns)->firstWhere('name', 'description');

    expect($description['isToggled'])->toBeFalse();
});
