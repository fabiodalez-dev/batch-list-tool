<?php

declare(strict_types=1);

use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Cataloguer report, 2026-09-14 (subseries import). Three complaints, one
 * cause: the ISAD(G) values imported from the client's sheet — Level of
 * description, Date of creation, Name of Inputter — existed on the record and
 * on its detail page, but the LIST had no column for any of them. What the
 * list did show was `created_at` (the moment of the import, i.e. "today") and
 * the audit-derived Inputter (whoever was logged in while importing). The data
 * was never wrong; the list was reading the wrong three things.
 *
 * These tests pin the columns to the SHEET's values, and pin the audit column
 * to a different name so the two can never silently collapse into each other
 * again. Fixtures are seeded against the in-memory SQLite DB.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function seriesCols_admin(string $name): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'name' => $name,
        'email' => 'series-cols+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/** Render one column of the Series list for one record and return its state. */
function seriesCols_render(User $actor, Series $record, string $column): mixed
{
    test()->actingAs($actor);
    $table = Livewire::test(ListSeries::class)->instance()->getTable();
    $col = $table->getColumn($column);
    expect($col)->not->toBeNull("the Series list has no '{$column}' column");
    $col->record($record);

    return $col->getState();
}

it('shows the sheet values, not the import timestamp or the logged-in user', function (): void {
    // The sheet was filled in by one cataloguer and imported by another —
    // exactly the case the report describes ("takes logged in person rather
    // than the name of the template"). When the same person does both, the two
    // columns agree by coincidence and the bug is invisible.
    $importer = seriesCols_admin('Maria Pia Aquilina');
    $this->actingAs($importer);

    $series = Series::create([
        'code' => 'REG',
        'title' => 'Registers',
        'level_of_description' => 'SubSeries',
        'date_of_creation' => '2026-07-29',
        'name_of_inputter' => 'Iaci, Marsha',
    ]);

    expect(seriesCols_render($importer, $series, 'level_of_description'))->toBe('SubSeries');
    expect(seriesCols_render($importer, $series, 'name_of_inputter'))->toBe('Iaci, Marsha');

    // The date must come from the sheet. Pinning it to the literal value is
    // what catches a regression to `created_at`, which would render today.
    $shown = seriesCols_render($importer, $series, 'date_of_creation');
    expect($shown)->toBe('2026-07-29');
    expect($shown)->not->toBe(now()->format('Y-m-d'));
});

it('keeps the audit column separate from the sheet, under a distinct label', function (): void {
    $importer = seriesCols_admin('Maria Pia Aquilina');
    $this->actingAs($importer);

    // The suite runs from the CLI and config/audit.php sets `console => false`,
    // so nothing is audited here by default and the audit column would be null
    // for reasons that have nothing to do with what this test is about.
    config(['audit.console' => true]);

    $series = Series::create([
        'code' => 'RWL',
        'title' => 'Registers Public Wills',
        'name_of_inputter' => 'Iaci, Marsha',
    ]);

    // Sheet column → the cataloguer named in the file.
    expect(seriesCols_render($importer, $series, 'name_of_inputter'))->toBe('Iaci, Marsha');
    // Audit column → the account that performed the import.
    expect(seriesCols_render($importer, $series, 'inputter'))->toBe('Maria Pia Aquilina');

    $table = Livewire::test(ListSeries::class)->instance()->getTable();
    expect((string) $table->getColumn('name_of_inputter')->getLabel())->toBe('Name of Inputter');
    // Two columns both labelled "…Inputter", disagreeing on the same row, is
    // what made the audit one look broken. Asserting merely that the labels
    // are not IDENTICAL would be satisfied by the original "Inputter" vs
    // "Name of Inputter" pairing — that is precisely the confusing state being
    // fixed — so require that the audit label not contain the word at all.
    expect(strtolower((string) $table->getColumn('inputter')->getLabel()))
        ->not->toContain('inputter');
});

it('leaves an ISAD(G) date span intact instead of parsing it as a date', function (): void {
    $actor = seriesCols_admin('NRA Admin');
    $this->actingAs($actor);

    $series = Series::create([
        'code' => 'OWL',
        'title' => 'Originals Public Wills',
        // Free text by design: the archival description of a span, not a day.
        'date_of_creation' => '1607-1629',
    ]);

    expect(seriesCols_render($actor, $series, 'date_of_creation'))->toBe('1607-1629');
});

it('shows all three sheet columns by default, without opening the toggle panel', function (): void {
    $actor = seriesCols_admin('NRA Admin');
    $this->actingAs($actor);

    $table = Livewire::test(ListSeries::class)->instance()->getTable();

    foreach (['level_of_description', 'date_of_creation', 'name_of_inputter'] as $name) {
        $col = $table->getColumn($name);
        expect($col)->not->toBeNull("missing column '{$name}'");
        expect($col->isToggledHiddenByDefault())->toBeFalse(
            "'{$name}' is hidden by default — the cataloguer would still not see it"
        );
    }
});
