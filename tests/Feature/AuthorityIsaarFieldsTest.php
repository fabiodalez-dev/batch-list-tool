<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
 * Client request, 2026-09-16 — ISAAR(CPF) fields on Authorities: a third
 * identifier (the warrant number) and seven descriptive fields, plus Notes,
 * which existed on the form and in the database but had no import column, so
 * it could be typed and never imported.
 *
 * "Identifier" is renamed to "Authority Record Identifier (NAM)" in the
 * template. The field is untouched; only the header changes, and the importer
 * still answers to the old spellings.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function isaar_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/** Drive the importer's real pipeline over one row, as the queue job does. */
function isaar_import(array $data, int $userId): void
{
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'authorities.xlsx',
        'file_path' => '/tmp/authorities.xlsx',
        'importer' => AuthorityImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    (new AuthorityImporter($import, $columnMap, []))($data);
}

/* ── template ─────────────────────────────────────────────────────────── */

it('renames Identifier and adds the warrant number as a third identifier', function (): void {
    $headers = TemplateGenerator::headersFor('authority');

    expect($headers[0])->toBe('Authority Record Identifier (NAM)');
    expect($headers)->toContain('Alternative Identifier');
    expect($headers)->toContain('Alternative Identifier (Warrant Number)');
    // The bare old name must be gone from the template, or the cataloguer sees
    // two columns meaning the same thing.
    expect($headers)->not->toContain('Identifier');
});

it('carries every new descriptive column, Notes included', function (): void {
    $headers = TemplateGenerator::headersFor('authority');

    foreach ([
        'Authorised form of name',
        'Functions, occupations and activities',
        'Level of detail',
        'Status',
        'Rules and/or conventions',
        'Date of creation',
        'Creator of record',
        'Notes',
    ] as $expected) {
        expect($headers)->toContain($expected);
    }
});

it('maps every template header onto an importer column without manual mapping', function (): void {
    // A column the operator has to map by hand is a column most will leave
    // unmapped, and an unmapped column imports as blank with no error.
    $headers = TemplateGenerator::headersFor('authority');
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);

    expect($map['identifier'] ?? null)->toBe('Authority Record Identifier (NAM)');
    expect($map['alternative_identifier_warrant'] ?? null)->toBe('Alternative Identifier (Warrant Number)');
    expect($map['level_of_detail'] ?? null)->toBe('Level of detail');
    expect($map['status'] ?? null)->toBe('Status');
    expect($map['date_of_creation'] ?? null)->toBe('Date of creation');
    expect($map['creator_of_record'] ?? null)->toBe('Creator of record');
    expect($map['notes'] ?? null)->toBe('Notes');
    expect($map['authorised_form_of_name'] ?? null)->toBe('Authorised form of name');
    expect($map['functions_occupations_activities'] ?? null)->toBe('Functions, occupations and activities');
    expect($map['rules_and_conventions'] ?? null)->toBe('Rules and/or conventions');
});

it('still recognises a sheet that uses the old Identifier header', function (): void {
    // Sheets saved before today must keep importing without remapping.
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, ['Identifier', 'Creator Surname']);

    expect($map['identifier'] ?? null)->toBe('Identifier');
});

/* ── importer ─────────────────────────────────────────────────────────── */

it('imports the descriptive fields', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    isaar_import([
        'identifier' => 'NAM-1',
        'surname' => 'Iaci',
        'given_names' => 'Marsha',
        'entity_type' => 'Notary',
        'alternative_identifier_warrant' => 'W-4471',
        'authorised_form_of_name' => 'Iaci, Marsha',
        'functions_occupations_activities' => 'Notary public, Valletta',
        'level_of_detail' => 'Full Level',
        'status' => 'Complete',
        'rules_and_conventions' => 'ISAAR(CPF), 2nd ed.',
        'creator_of_record' => 'Iaci, Marsha',
    ], $u->id);

    $a = Authority::where('identifier', 'NAM-1')->firstOrFail();

    expect($a->alternative_identifier_warrant)->toBe('W-4471');
    expect($a->authorised_form_of_name)->toBe('Iaci, Marsha');
    expect($a->functions_occupations_activities)->toBe('Notary public, Valletta');
    expect($a->level_of_detail)->toBe('Full Level');
    expect($a->status)->toBe('Complete');
    expect($a->rules_and_conventions)->toBe('ISAAR(CPF), 2nd ed.');
    expect($a->creator_of_record)->toBe('Iaci, Marsha');
});

it('stores the canonical spelling of the two dropdowns', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    // Operators type what they remember. Storing "full level" and "Full Level"
    // as different values would split every filter and count in two.
    isaar_import([
        'identifier' => 'NAM-2', 'surname' => 'Abela', 'given_names' => 'Sam', 'entity_type' => 'Notary',
        'level_of_detail' => '  full LEVEL ',
        'status' => 'in progress',
    ], $u->id);

    $a = Authority::where('identifier', 'NAM-2')->firstOrFail();
    expect($a->level_of_detail)->toBe('Full Level');
    expect($a->status)->toBe('In Progress');
});

it('rejects a value outside the allowed list instead of storing it', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    // "ful" would sit in the record forever, matching no filter and flagged by
    // nothing. Failing the row puts it in the failed-rows file with the valid
    // options spelled out.
    expect(fn () => isaar_import([
        'identifier' => 'NAM-3', 'surname' => 'Borg', 'given_names' => 'Anna', 'entity_type' => 'Notary',
        'level_of_detail' => 'ful',
    ], $u->id))->toThrow(ValidationException::class);

    expect(Authority::where('identifier', 'NAM-3')->exists())->toBeFalse();
});

it('blames the field that actually failed, not always Level of detail', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    // Both dropdowns share one matcher. If the message is filed under the
    // wrong key, a bad Status reads as a Level of detail problem and the
    // cataloguer goes looking in the wrong column.
    try {
        isaar_import([
            'identifier' => 'NAM-3b', 'surname' => 'Borg', 'given_names' => 'Anna', 'entity_type' => 'Notary',
            'status' => 'nearly done',
        ], $u->id);
        $this->fail('An unlisted Status should have been rejected.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['status']);
        expect($e->errors()['status'][0])->toContain('In Progress, Complete');
    }

    expect(Authority::where('identifier', 'NAM-3b')->exists())->toBeFalse();
});

it('keeps an archival date span as written, and converts an Excel serial', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    isaar_import(['identifier' => 'NAM-4', 'surname' => 'A', 'given_names' => 'B', 'entity_type' => 'Notary', 'date_of_creation' => '1607-1629'], $u->id);
    isaar_import(['identifier' => 'NAM-5', 'surname' => 'C', 'given_names' => 'D', 'entity_type' => 'Notary', 'date_of_creation' => '46232'], $u->id);

    expect(Authority::where('identifier', 'NAM-4')->value('date_of_creation'))->toBe('1607-1629');
    // Excel turns a typed date into a serial; "46232" in the record would be
    // meaningless to the cataloguer.
    // 46232 is the serial Excel wrote for 29 July 2026 — the value that appears
    // verbatim in the client's own sheets.
    expect(Authority::where('identifier', 'NAM-5')->value('date_of_creation'))->toBe('2026-07-29');
});

it('imports Notes without the maiden-surname line overwriting it', function (): void {
    $u = isaar_admin();
    $this->actingAs($u);

    // maiden_surname APPENDS a line to notes. Filament fills columns in the
    // order they are declared, so declaring notes after it would have the
    // imported note replace the appended line and lose it silently.
    isaar_import([
        'identifier' => 'NAM-6', 'surname' => 'Camilleri', 'given_names' => 'Rita', 'entity_type' => 'Notary',
        'notes' => 'Transferred from the Gozo section.',
        'maiden_surname' => 'Zammit',
    ], $u->id);

    $notes = (string) Authority::where('identifier', 'NAM-6')->value('notes');

    expect($notes)->toContain('Transferred from the Gozo section.');
    expect($notes)->toContain('Maiden surname: Zammit');
});
