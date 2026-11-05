<?php

declare(strict_types=1);

use App\Models\Authority;

/**
 * Client 2026-11-05, Issue 5, in the real browser: integer option keys could be
 * reordered ascending on their way to the page, and a stored integer could fail
 * to show as the selected option. Neither shows in a Livewire test.
 */
beforeEach(function () {
    bl_seedShieldPermissions();
});

it('lists the years newest first in the browser', function () {
    $page = bl_login(bl_actor('super_admin'))->navigate('/admin/authorities/create');

    $page->assertSee('Control Area')->assertSee('Select Year');
    $page->script("document.querySelector('[id=\"form.practice_dates_start\"], [id=\"data.practice_dates_start\"]').closest('.fi-fo-field').querySelector('button').click()");

    $years = $page->script("Array.from(document.querySelectorAll('.fi-dropdown-panel li, .fi-select-input-option')).map(e => e.textContent.trim()).filter(t => /^\\d{4}$/.test(t))");

    expect($years[0])->toBe((string) now()->year)
        ->and($years[1])->toBe((string) (now()->year - 1));
});

it('shows a stored year as selected on the edit page, also one outside the range', function () {
    $a = Authority::create([
        'alternative_identifier' => 'R-YEAR-1', 'surname' => 'Psaila', 'given_names' => 'Giuseppe', 'entity_type' => 'Person',
        'practice_dates_start' => 1350, 'practice_dates_end' => 1878,
    ]);

    bl_login(bl_actor('super_admin'))
        ->navigate("/admin/authorities/{$a->id}/edit")
        ->assertSee('1350')
        ->assertSee('1878');
});

it('opens the two lookup pages for an admin', function () {
    bl_login(bl_actor('admin'))
        ->navigate('/admin/lookups/entity-types')
        ->assertSee('Person');

    bl_login(bl_actor('admin'))
        ->navigate('/admin/lookups/custody-status-types')
        ->assertSee('Not in Box');
});
