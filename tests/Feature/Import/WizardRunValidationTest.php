<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client blocker (2026-07-27): in the Import Wizard the "Run validation" button
 * did nothing and would not let the operator proceed.
 *
 * Root cause: runPreflight() read the wizard state with $this->form->getState(),
 * which validates EVERY step's fields — including the required "confirm"
 * checkbox on the final Confirm step, which is still empty while the operator is
 * on the Validate step. That threw a Halt which runPreflight silently swallowed,
 * so the button appeared dead and preflightResult stayed null (the Next gate
 * then blocked the wizard).
 *
 * Fix: runPreflight() now reads getRawState() (no whole-form validation).
 */
uses(RefreshDatabase::class);

function wrv_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

test('Run validation does not silently halt on the required confirm checkbox — it reaches the file check', function () {
    $this->actingAs(wrv_admin());

    // Pick a type but do NOT tick the Confirm-step checkbox and do NOT upload a
    // file — exactly the state while sitting on the Validate step. Before the
    // fix, runPreflight() halted on getState() and sent NO notification. After
    // the fix it gets past state-reading and reports the missing file.
    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->call('runPreflight')
        ->assertNotified('Import not started'); // reached the file check instead of dying on validation
});

test('the whole-form getState() halts on the empty required confirm checkbox (the trap runPreflight must avoid)', function () {
    $this->actingAs(wrv_admin());

    $component = Livewire::test(ImportWizard::class)->set('data.import_type', 'series');
    $form = $component->instance()->form;

    // getRawState() reads what the operator entered so far without validating.
    expect($form->getRawState()['import_type'])->toBe('series');

    // getState() validates the entire schema, so the empty required "confirm"
    // checkbox on the Confirm step makes it throw — this is exactly why the old
    // runPreflight() (which used getState) died before it could validate rows.
    expect(fn () => $form->getState())->toThrow(ValidationException::class);
});
