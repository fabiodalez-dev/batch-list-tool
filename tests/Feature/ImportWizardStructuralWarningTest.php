<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Client, 2026-09-16: "somehow it builds the parent-child relation only for 3
 * records". It had built none. The sheet was saved before the Parent column
 * existed, so there was no column to map — 19 rows in, 19 rows imported, zero
 * failures, and every signal the operator had said success. The three links
 * she saw were ones she had set by hand two days earlier.
 *
 * An unmapped optional column is normally none of the wizard's business. One
 * that silently switches off the feature the operator came to use is, so the
 * validation step now says so before the import runs.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function isw_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/** Render the preflight panel with a given wizard state. */
function isw_render(array $state, array $preflight): string
{
    $component = Livewire::test(ImportWizard::class);
    $page = $component->instance();

    $prop = new ReflectionProperty(ImportWizard::class, 'preflightResult');
    $prop->setAccessible(true);
    $prop->setValue($page, $preflight);

    $page->form->rawState($state);

    $method = new ReflectionMethod(ImportWizard::class, 'renderPreflightResult');
    $method->setAccessible(true);

    return (string) $method->invoke($page);
}

it('warns that no hierarchy will be built when the Parent column is absent', function (): void {
    $this->actingAs(isw_admin());

    // Charlene's sheet: the six pre-14-September columns, every row valid.
    $html = isw_render(
        [
            'import_type' => 'series',
            'column_map' => [
                'code' => 'Identifier',
                'title' => 'Standard title in English (Plural)',
                'level_of_description' => 'Level of description',
                'date_of_creation' => 'Date of creation',
                'name_of_inputter' => 'Name of Inputter',
                'repository_code' => 'Repository',
                // no parent_code — the column is not in the file
            ],
        ],
        ['total' => 19, 'valid' => 19, 'invalid' => 0, 'errors' => [], 'truncated' => false],
    );

    // The success line must still be there; the warning sits next to it.
    expect($html)->toContain('All 19 rows pass validation');
    expect($html)->toContain('Heads up before you import');
    expect($html)->toContain('No &quot;Parent&quot; column is mapped');
    // It must point at the fix, not merely state the fact.
    expect($html)->toContain('download the template again');
});

it('says nothing when the Parent column is mapped', function (): void {
    $this->actingAs(isw_admin());

    $html = isw_render(
        [
            'import_type' => 'series',
            'column_map' => ['code' => 'Identifier', 'parent_code' => 'Parent'],
        ],
        ['total' => 8, 'valid' => 8, 'invalid' => 0, 'errors' => [], 'truncated' => false],
    );

    expect($html)->toContain('All 8 rows pass validation');
    expect($html)->not->toContain('Heads up before you import');
});

it('still warns when some rows fail, since that panel is the one being read', function (): void {
    $this->actingAs(isw_admin());

    $html = isw_render(
        [
            'import_type' => 'series',
            'column_map' => ['code' => 'Identifier'],
        ],
        [
            'total' => 5,
            'valid' => 4,
            'invalid' => 1,
            'errors' => [['row' => 2, 'field' => 'title', 'message' => 'required']],
            'truncated' => false,
        ],
    );

    expect($html)->toContain('1 of 5 rows would fail validation');
    expect($html)->toContain('Heads up before you import');
});

it('leaves importers with no structural columns untouched', function (): void {
    $this->actingAs(isw_admin());

    $html = isw_render(
        [
            'import_type' => 'authorities',
            'column_map' => ['identifier' => 'Identifier'],
        ],
        ['total' => 3, 'valid' => 3, 'invalid' => 0, 'errors' => [], 'truncated' => false],
    );

    expect($html)->toContain('All 3 rows pass validation');
    expect($html)->not->toContain('Heads up before you import');
});
