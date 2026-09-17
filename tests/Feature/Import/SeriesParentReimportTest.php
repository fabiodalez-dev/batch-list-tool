<?php

declare(strict_types=1);

use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/*
 * Client report, 2026-09-14 and again on the 16th: "somehow it builds the
 * parent-child relation only for 3 records".
 *
 * The cause was not the importer. The sheet she uploaded on both dates — read
 * back from storage/app/private/imports on the server — carries six columns and
 * no "Parent", because it was downloaded before that column existed. The three
 * relations she could see had been set by hand earlier.
 *
 * This test reproduces her exact situation: her nineteen rows already in the
 * database with no parent, then the same rows re-imported from the current
 * template. It exists to pin the part that is easy to get wrong — re-importing
 * with the overwrite checkbox left alone silently changes nothing, because
 * every row already exists and is skipped.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/**
 * The nineteen rows of 20260916_050330_1acad91f.csv, verbatim.
 *
 * "46232" is the Excel serial her sheet carries for the creation date, kept as
 * she typed it so the importer's serial handling is exercised too.
 *
 * @return list<array<string, string>>
 */
function spr_herRows(): array
{
    $raw = [
        ['R', 'Register Copies (Registro)', 'Series'],
        ['REG', 'Registers', 'SubSeries'],
        ['RWL', 'Registers Public Wills', 'SubSeries'],
        ['RDP', 'Duplicate Registers', 'SubSeries'],
        ['RPA', 'Registers Power of Attorney', 'SubSeries'],
        ['O', 'Originals (Minutari)', 'Series'],
        ['ORG', 'Originals', 'SubSeries'],
        ['OWL', 'Originals Public Wills', 'SubSeries'],
        ['B', 'Bastardelli', 'Series'],
        ['I', 'Indexes', 'Series'],
        ['IREG', 'Indexes of Registers', 'SubSeries'],
        ['IRWL', 'Indexes of Registers Public Wills', 'SubSeries'],
        ['IORG', 'Indexes of Originals', 'SubSeries'],
        ['IOWL', 'Indexes of Originals Public Wills', 'SubSeries'],
        ['IIP', 'Indexes of Interdicted Persons', 'SubSeries'],
        ['IB', 'Indexes of Bastardelli', 'SubSeries'],
        ['F', 'Fragments', 'Series'],
        ['M', 'Miscellanea', 'Series'],
        ['MRT', 'Maritime Collection', 'Series'],
    ];

    return array_map(fn (array $r): array => [
        'Identifier' => $r[0],
        'Standard title in English (Plural)' => $r[1],
        'Level of description' => $r[2],
        'Date of creation' => '46232',
        'Name of Inputter' => 'Iaci, Marsha',
        'Repository' => 'NRA',
    ], $raw);
}

/**
 * The parents she listed, in her own words:
 * R>RPA, O>ORG, O>OWL, I>IREG, I>IRWL, I>IORG, I>IOWL, I>IIP, I>IB — plus the
 * three under R that were already set by hand.
 *
 * @return array<string, string>
 */
function spr_expectedParents(): array
{
    return [
        'REG' => 'R', 'RWL' => 'R', 'RDP' => 'R', 'RPA' => 'R',
        'ORG' => 'O', 'OWL' => 'O',
        'IREG' => 'I', 'IRWL' => 'I', 'IORG' => 'I', 'IOWL' => 'I', 'IIP' => 'I', 'IB' => 'I',
    ];
}

/** The nine she reported as missing — the three under R already worked. */
function spr_theNine(): array
{
    $all = spr_expectedParents();
    unset($all['REG'], $all['RWL'], $all['RDP']);

    return $all;
}

/** Her rows as the CURRENT template shapes them: same values, plus Parent. */
function spr_rowsWithParent(): array
{
    $parents = spr_expectedParents();

    return array_map(function (array $row) use ($parents): array {
        $out = [];
        foreach (TemplateGenerator::headersFor('series') as $header) {
            $out[$header] = $header === 'Parent'
                ? ($parents[$row['Identifier']] ?? '')
                : ($row[$header] ?? '');
        }

        return $out;
    }, spr_herRows());
}

function spr_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['email' => 'reimport+' . uniqid() . '@test.local', 'is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the importer over every row, as the queue job does, honouring the
 * wizard's overwrite checkbox.
 *
 * @param list<array<string, string>> $rows
 * @return array{0: int, 1: int} [imported, skipped]
 */
function spr_import(array $rows, bool $overwriteExisting, int $userId): array
{
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'series.csv', 'file_path' => '/tmp/series.csv',
        'importer' => SeriesImporter::class, 'processed_rows' => 0,
        'total_rows' => count($rows), 'successful_rows' => 0, 'user_id' => $userId,
    ]);

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, array_keys($rows[0]));
    // The wizard passes the inverse of the checkbox.
    $importer = new SeriesImporter($import, $map, ['skip_duplicates' => ! $overwriteExisting]);

    $imported = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        try {
            $importer($row);
            $imported++;
        } catch (Throwable) {
            $skipped++;
        }
    }

    return [$imported, $skipped];
}

/** How many of the expected parent links actually hold. */
function spr_correctLinks(array $expected): int
{
    $n = 0;
    foreach ($expected as $child => $parent) {
        $c = Series::where('code', $child)->first();
        if ($c?->parent_id === null) {
            continue;
        }
        if (Series::where('id', $c->parent_id)->value('code') === $parent) {
            $n++;
        }
    }

    return $n;
}

/** Put the database where production is: her rows present, none with a parent. */
function spr_seedAsProduction(int $userId): void
{
    Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    spr_import(spr_herRows(), true, $userId);
    Series::query()->update(['parent_id' => null]);
}

it('reproduces the report: her own sheet cannot build the hierarchy', function (): void {
    $user = spr_admin();
    $this->actingAs($user);

    Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    [$imported] = spr_import(spr_herRows(), true, $user->id);

    expect($imported)->toBe(19);
    // Every subseries is labelled "SubSeries", which is why the hierarchy looks
    // like it should be there — the column names the level and attaches nothing.
    expect(Series::where('level_of_description', 'SubSeries')->count())->toBe(12);
    expect(spr_correctLinks(spr_expectedParents()))->toBe(0);
});

it('builds all nine links from the current template', function (): void {
    $user = spr_admin();
    $this->actingAs($user);
    spr_seedAsProduction($user->id);

    expect(spr_correctLinks(spr_theNine()))->toBe(0);

    [$imported, $skipped] = spr_import(spr_rowsWithParent(), true, $user->id);

    expect($imported)->toBe(19);
    expect($skipped)->toBe(0);
    expect(spr_correctLinks(spr_theNine()))->toBe(9);
    // The three that were already right stay right.
    expect(spr_correctLinks(spr_expectedParents()))->toBe(12);
});

it('changes nothing when the overwrite box is left alone', function (): void {
    $user = spr_admin();
    $this->actingAs($user);
    spr_seedAsProduction($user->id);

    // This is the trap. Every row already exists, so every row is skipped and
    // not one parent is set — with no error and no warning. Re-importing after
    // adding the Parent column only works with the box ticked.
    [$imported, $skipped] = spr_import(spr_rowsWithParent(), false, $user->id);

    expect($imported)->toBe(0);
    expect($skipped)->toBe(19);
    expect(spr_correctLinks(spr_theNine()))->toBe(0);
});

it('reads her Excel serial as a date while leaving the rest of the row alone', function (): void {
    $user = spr_admin();
    $this->actingAs($user);
    spr_seedAsProduction($user->id);
    spr_import(spr_rowsWithParent(), true, $user->id);

    $reg = Series::where('code', 'REG')->first();

    // 46232 is what Excel writes when she types a date; it must reach the record
    // readable, not as the number.
    expect($reg->date_of_creation)->toBe('2026-07-29');
    expect($reg->name_of_inputter)->toBe('Iaci, Marsha');
    expect($reg->level_of_description)->toBe('SubSeries');
});
