<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\User;
use Filament\Schemas\Components\Wizard;
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

test('the wizard submit button SUBMITS the surrounding form (never a mountable action) — the real "wizard import does nothing" bug', function () {
    // Client blocker (2026-07-28): importing through the wizard did nothing —
    // only the per-resource pages worked. Root cause: the Wizard submitAction was
    // built with ->action(fn () => $this->startImport()). In Filament v5 a wizard
    // submitAction carrying an ->action() closure renders as
    // wire:click="mountAction('startImport')", which (a) preventDefault()s the
    // native submit so the page's <form wire:submit="startImport"> never fires,
    // and (b) cannot be resolved as a mountable page action — so clicking "Start
    // import" ran NOTHING and no Import row was ever created.
    //
    // Fix: ->submit('startImport') instead of ->action(...), so the button is a
    // plain type="submit" that submits the form and runs the startImport() method.
    // This guards against a regression back to ->action(): the button must report
    // canSubmitForm() === true (submit), and the form it targets must be the
    // page's startImport handler.
    $this->actingAs(wrv_admin());

    $form = Livewire::test(ImportWizard::class)->instance()->form;

    /** @var Wizard $wizard */
    $wizard = collect($form->getComponents())
        ->first(fn ($c): bool => $c instanceof Wizard);

    expect($wizard)->not->toBeNull();

    $submit = $wizard->getSubmitAction();

    // A submit button, not a mountable action: canSubmitForm() true and it targets
    // the 'startImport' form handler. If someone reverts to ->action(), the button
    // stops submitting the form and the wizard silently imports nothing again.
    expect($submit->canSubmitForm())->toBeTrue()
        ->and($submit->getFormToSubmit())->toBe('startImport');
});

test('preflight applies the column cast before validating — no false error on values the import would normalise', function () {
    // AuthorityImporter casts "Type of Entity" via normaliseEntityType() before
    // the in:PERSON,INSTITUTION rule. The preflight must apply that cast too,
    // otherwise a legitimate "Notary" row (which the real import normalises to
    // INSTITUTION) would be falsely reported as invalid — the exact false error
    // that showed up when running the example authority template through the
    // wizard preflight.
    $headers = ['Identifier', 'Type of Entity', 'Creator Surname'];
    $rows = [
        ['Identifier' => 'R1', 'Type of Entity' => 'Notary', 'Creator Surname' => 'Caruana'],
        ['Identifier' => 'R2', 'Type of Entity' => 'PERSON', 'Creator Surname' => 'Borg'],
    ];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);

    $result = ImportWizard::validateRows(AuthorityImporter::class, $rows, $map);

    // Both rows valid: "Notary" casts to INSTITUTION and passes the
    // in:PERSON,INSTITUTION rule (before the fix it was falsely reported invalid).
    expect($result['invalid'])->toBe(0)
        ->and($result['valid'])->toBe(2);
});
