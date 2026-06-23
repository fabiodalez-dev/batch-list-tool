<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Box;
use App\Models\Document;
use App\Models\Lookup\BatchType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\Import\BatchListColumnMap;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * NAF New-Accession importer — exhaustive coverage of the gaps closed from the
 * Sam Abela sample (the "Batch list format" sheet) and the data-quality fixes
 * that followed:
 *
 *   A. bare "Name" header feeds the Authority given name
 *   B. "Status" header sets the Box custody status (IN/OUT/PERM_OUT), not box_type
 *   D. "Accession Date" lands on the Accession (DD/MM/YYYY + Excel datetime)
 *   F. bare "Identifier" is the Authority R-number, NOT the document identifier
 *      (the document column is named 'document_identifier' so guessColumnMap's
 *      tier-1 name/label match can no longer claim the bare "Identifier" header)
 *   + Excel float artefacts on the R-number are normalised (642.0 -> 642)
 */
uses(RefreshDatabase::class);

/* ─── helpers ────────────────────────────────────────────────────────── */

function ar_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function ar_admin(int $repoId): User
{
    ar_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'ar-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Create the minimal world an accession row needs (repository NRA, series REG,
 * the NOTARY_ACCESSION lookup) and return the acting admin.
 */
function ar_setup(): User
{
    $repo = Repository::factory()->create(['code' => 'NRA']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Register', 'is_active' => true]);
    // NOTARY_ACCESSION is one of the two values the batches.type enum accepts
    // under sqlite; it is also seeded into batch_types by the lookup migration.
    BatchType::query()->firstOrCreate(['code' => 'NOTARY_ACCESSION'], ['label' => 'Notary Accession', 'is_active' => true]);
    $admin = ar_admin($repo->id);
    test()->actingAs($admin);

    return $admin;
}

/**
 * A complete, valid accession row keyed by ImportColumn names. Pass overrides
 * for the field under test.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ar_row(array $overrides = []): array
{
    return [
        'authority_identifier' => '500',
        'authority_name' => 'Mario',
        'authority_surname' => 'Rossi',
        'accession_number' => 'ACC-1',
        'accession_title' => 'Title',
        'accession_date' => '01/01/2020',
        'batch_number' => '700',
        'accession_type' => 'NOTARY_ACCESSION',
        'repository' => 'NRA',
        'box_number' => '1',
        'box_barcode' => 'BC-1',
        'box_barcode_status' => 'IN',
        'box_type' => 'RAS',
        'document_type' => 'Will',
        'series' => 'REG',
        ...$overrides,
    ];
}

/**
 * Drive the importer with an inline row keyed by ImportColumn names (the
 * identity column-map a confirmed wizard mapping produces).
 *
 * @param array<string, mixed> $data
 */
function ar_run(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'sam_abela.xlsx',
        'file_path' => '/tmp/sam_abela.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    /** @var Importer $importer */
    $importer = new AccessionRowImporter($row, $columnMap, []);
    $importer($data);
}

function ar_guess(array $headers): array
{
    return ImportWizard::guessColumnMap(AccessionRowImporter::class, $headers);
}

function ar_authority(string $identifier): ?Authority
{
    return Authority::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', $identifier)->first();
}

function ar_latestDoc(): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();
}

function ar_box(Document $doc): ?Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
}

function ar_accession(Document $doc): ?Accession
{
    return Accession::withoutGlobalScope(RepositoryScope::class)->find($doc->accession_id);
}

/* ─── GAP A — bare "Name" feeds the authority given name ─────────────── */

describe('Gap A — authority name', function () {
    test('bare "Name" header maps to the authority_name column', function () {
        expect(ar_guess(['Name'])['authority_name'])->toBe('Name');
    });

    test('"Authority Name" header maps to the authority_name column', function () {
        expect(ar_guess(['Authority Name'])['authority_name'])->toBe('Authority Name');
    });

    test('given_names is set from the name column on import', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '510', 'authority_name' => 'Giulio']), $admin->id);
        expect(ar_authority('510')?->given_names)->toBe('Giulio');
    });

    test('given_names and surname are both stored', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '511', 'authority_name' => 'Anna', 'authority_surname' => 'Bianchi']), $admin->id);
        $a = ar_authority('511');
        expect($a?->given_names)->toBe('Anna')->and($a?->surname)->toBe('Bianchi');
    });

    test('a freshly created authority gets entity_type Notary', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '512']), $admin->id);
        expect(ar_authority('512')?->entity_type)->toBe('Notary');
    });
});

/* ─── GAP B — "Status" sets the box custody status ──────────────────── */

describe('Gap B — box custody status', function () {
    test('"Status" header maps to box_barcode_status', function () {
        expect(ar_guess(['Status'])['box_barcode_status'])->toBe('Status');
    });

    test('"Box Status" header maps to box_barcode_status', function () {
        expect(ar_guess(['Box Status'])['box_barcode_status'])->toBe('Box Status');
    });

    test('"Status" does NOT leak into the box_type column', function () {
        expect(ar_guess(['Status'])['box_type'] ?? null)->not->toBe('Status');
    });

    test('IN is stored verbatim on the box', function () {
        $admin = ar_setup();
        ar_run(ar_row(['box_barcode' => 'BC-IN', 'box_barcode_status' => 'IN']), $admin->id);
        expect(ar_box(ar_latestDoc())?->barcode_status)->toBe('IN');
    });

    test('OUT is stored verbatim on the box', function () {
        $admin = ar_setup();
        ar_run(ar_row(['box_barcode' => 'BC-OUT', 'box_barcode_status' => 'OUT']), $admin->id);
        expect(ar_box(ar_latestDoc())?->barcode_status)->toBe('OUT');
    });

    // PERM_OUT boxes are forbidden without a disinfestation date (RFQ A1.2),
    // and this sheet carries none — so a normalised PERM_OUT must surface that
    // box-level rule. A literal, un-normalised "PERM OUT" would NOT trigger the
    // PERM_OUT-specific rule, so the throw itself proves the value was mapped.
    test('PERM OUT with a space is normalised to PERM_OUT', function () {
        $admin = ar_setup();
        expect(fn () => ar_run(ar_row(['box_barcode' => 'BC-P1', 'box_barcode_status' => 'PERM OUT']), $admin->id))
            ->toThrow(ValidationException::class, 'disinfestation');
    });

    test('PERM-OUT with a hyphen is normalised to PERM_OUT', function () {
        $admin = ar_setup();
        expect(fn () => ar_run(ar_row(['box_barcode' => 'BC-P2', 'box_barcode_status' => 'PERM-OUT']), $admin->id))
            ->toThrow(ValidationException::class, 'disinfestation');
    });

    test('PERMOUT with no separator is normalised to PERM_OUT', function () {
        $admin = ar_setup();
        expect(fn () => ar_run(ar_row(['box_barcode' => 'BC-P3', 'box_barcode_status' => 'PERMOUT']), $admin->id))
            ->toThrow(ValidationException::class, 'disinfestation');
    });

    test('lowercase "out" is upper-cased to OUT', function () {
        $admin = ar_setup();
        ar_run(ar_row(['box_barcode' => 'BC-LO', 'box_barcode_status' => 'out']), $admin->id);
        expect(ar_box(ar_latestDoc())?->barcode_status)->toBe('OUT');
    });

    test('box_type stays RAS — Status never pollutes it', function () {
        $admin = ar_setup();
        ar_run(ar_row(['box_barcode' => 'BC-RAS', 'box_barcode_status' => 'OUT', 'box_type' => 'RAS']), $admin->id);
        expect(ar_box(ar_latestDoc())?->box_type)->toBe('RAS');
    });

    test('empty Status leaves the box at its IN default', function () {
        $admin = ar_setup();
        ar_run(ar_row(['box_barcode' => 'BC-DEF', 'box_barcode_status' => '']), $admin->id);
        expect(ar_box(ar_latestDoc())?->barcode_status)->toBe('IN');
    });
});

/* ─── GAP D — "Accession Date" lands on the accession ───────────────── */

describe('Gap D — accession date', function () {
    test('"Accession Date" header maps to the accession_date column', function () {
        expect(ar_guess(['Accession Date'])['accession_date'])->toBe('Accession Date');
    });

    test('European DD/MM/YYYY is parsed correctly', function () {
        $admin = ar_setup();
        ar_run(ar_row(['accession_number' => 'ACC-D1', 'accession_date' => '15/03/2024']), $admin->id);
        expect((string) ar_accession(ar_latestDoc())?->accession_date)->toContain('2024-03-15');
    });

    test('Excel datetime "YYYY-MM-DD 00:00:00" is parsed', function () {
        $admin = ar_setup();
        ar_run(ar_row(['accession_number' => 'ACC-D2', 'accession_date' => '2026-04-06 00:00:00']), $admin->id);
        expect((string) ar_accession(ar_latestDoc())?->accession_date)->toContain('2026-04-06');
    });

    test('ISO date is parsed', function () {
        $admin = ar_setup();
        ar_run(ar_row(['accession_number' => 'ACC-D3', 'accession_date' => '2025-12-31']), $admin->id);
        expect((string) ar_accession(ar_latestDoc())?->accession_date)->toContain('2025-12-31');
    });

    test('empty accession date leaves it null', function () {
        $admin = ar_setup();
        ar_run(ar_row(['accession_number' => 'ACC-D4', 'accession_date' => '']), $admin->id);
        expect(ar_accession(ar_latestDoc())?->accession_date)->toBeNull();
    });

    test('an unparseable date does not throw and leaves it null', function () {
        $admin = ar_setup();
        ar_run(ar_row(['accession_number' => 'ACC-D5', 'accession_date' => 'not-a-date']), $admin->id);
        expect(ar_accession(ar_latestDoc())?->accession_date)->toBeNull();
    });

    test('a blank date on an existing accession is back-filled', function () {
        $admin = ar_setup();
        $repoId = $admin->default_repository_id;
        $acc = Accession::withoutGlobalScope(RepositoryScope::class)->create([
            'accession_number' => 'ACC-BF', 'code' => 'ACC-BF',
            'accession_date' => null, 'repository_id' => $repoId,
        ]);
        ar_run(ar_row(['accession_number' => 'ACC-BF', 'accession_date' => '10/10/2010']), $admin->id);
        expect((string) $acc->fresh()->accession_date)->toContain('2010-10-10');
    });

    test('an existing accession date is never overwritten', function () {
        $admin = ar_setup();
        $repoId = $admin->default_repository_id;
        $acc = Accession::withoutGlobalScope(RepositoryScope::class)->create([
            'accession_number' => 'ACC-NO', 'code' => 'ACC-NO',
            'accession_date' => '1999-09-09', 'repository_id' => $repoId,
        ]);
        ar_run(ar_row(['accession_number' => 'ACC-NO', 'accession_date' => '10/10/2010']), $admin->id);
        expect((string) $acc->fresh()->accession_date)->toContain('1999-09-09');
    });
});

/* ─── GAP F — "Identifier" is the authority R-number ────────────────── */

describe('Gap F — identifier disambiguation', function () {
    test('BatchListColumnMap identifier aliases exclude the bare "Identifier"', function () {
        expect(BatchListColumnMap::aliases('identifier'))->not->toContain('Identifier');
    });

    test('BatchListColumnMap identifier aliases keep "Actual Identifier"', function () {
        expect(BatchListColumnMap::aliases('identifier'))->toContain('Actual Identifier');
    });

    test('bare "Identifier" maps to authority_identifier', function () {
        expect(ar_guess(['Identifier'])['authority_identifier'])->toBe('Identifier');
    });

    test('bare "Identifier" is NOT claimed by the document identifier column', function () {
        expect(ar_guess(['Identifier'])['document_identifier'] ?? null)->toBeNull();
    });

    test('"Actual Identifier" maps to the document identifier column', function () {
        expect(ar_guess(['Actual Identifier'])['document_identifier'])->toBe('Actual Identifier');
    });

    test('at runtime the R-number feeds the authority, leaving the document id clean', function () {
        $admin = ar_setup();
        // The R-number is mapped only to authority_identifier (the correct NAF
        // mapping); the document gets its own auto-generated identifier, never
        // the notary R-number.
        ar_run(ar_row(['authority_identifier' => '642']), $admin->id);
        expect(ar_authority('642'))->not->toBeNull();
        expect(ar_latestDoc()?->identifier)->not->toBe('642');
    });

    test('an explicit document identifier is written onto the document', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '643', 'document_identifier' => 'R1-V12']), $admin->id);
        expect(ar_latestDoc()?->identifier)->toBe('R1-V12');
    });
});

/* ─── Excel float artefacts on the R-number ─────────────────────────── */

describe('R-number float normalisation', function () {
    test('"642.0" is stored as 642', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '642.0']), $admin->id);
        expect(ar_authority('642'))->not->toBeNull()
            ->and(ar_authority('642.0'))->toBeNull();
    });

    test('"640.00" is stored as 640', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '640.00']), $admin->id);
        expect(ar_authority('640'))->not->toBeNull();
    });

    test('an alphanumeric R-code "R642" is left untouched', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => 'R642']), $admin->id);
        expect(ar_authority('R642'))->not->toBeNull();
    });

    test('a composite ref "180A" is left untouched', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '180A']), $admin->id);
        expect(ar_authority('180A'))->not->toBeNull();
    });

    test('a plain integer "642" is unchanged', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '642']), $admin->id);
        expect(ar_authority('642'))->not->toBeNull();
    });

    test('a genuine decimal "2.5" is NOT collapsed to 2', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '2.5']), $admin->id);
        expect(ar_authority('2.5'))->not->toBeNull()
            ->and(ar_authority('2'))->toBeNull();
    });

    test('multi-authority floats "642.0;643.0" split into 642 and 643', function () {
        $admin = ar_setup();
        ar_run(ar_row([
            'authority_identifier' => '642.0;643.0',
            'authority_name' => 'Uno;Due',
            'authority_surname' => 'A;B',
        ]), $admin->id);
        expect(ar_authority('642'))->not->toBeNull()
            ->and(ar_authority('643'))->not->toBeNull();
    });
});

/* ─── Full bottom-up cascade ────────────────────────────────────────── */

describe('Bottom-up cascade', function () {
    test('a complete row creates and links Authority, Accession, Batch, Box, Document', function () {
        $admin = ar_setup();
        ar_run(ar_row([
            'authority_identifier' => '900',
            'accession_number' => 'ACC-FULL',
            'batch_number' => '701',
            'box_barcode' => 'BC-FULL',
        ]), $admin->id);

        $doc = ar_latestDoc();
        expect($doc)->not->toBeNull()
            ->and($doc->accession_id)->not->toBeNull()
            ->and($doc->current_box_id)->not->toBeNull()
            ->and($doc->series_id)->not->toBeNull();
        expect(ar_authority('900'))->not->toBeNull();
        expect(ar_accession($doc)?->accession_number)->toBe('ACC-FULL');
        expect(ar_box($doc)?->barcode)->toBe('BC-FULL');
    });

    test('an existing accession number is reused, not duplicated', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '910', 'accession_number' => 'ACC-DUP', 'box_barcode' => 'BC-DUP', 'document_identifier' => 'D1']), $admin->id);
        ar_run(ar_row(['authority_identifier' => '910', 'accession_number' => 'ACC-DUP', 'box_barcode' => 'BC-DUP', 'document_identifier' => 'D2']), $admin->id);
        $count = Accession::withoutGlobalScope(RepositoryScope::class)->where('accession_number', 'ACC-DUP')->count();
        expect($count)->toBe(1);
    });

    test('two rows sharing a barcode reuse the same physical box', function () {
        $admin = ar_setup();
        ar_run(ar_row(['authority_identifier' => '920', 'box_barcode' => 'BC-SHARED', 'document_identifier' => 'D1']), $admin->id);
        ar_run(ar_row(['authority_identifier' => '920', 'box_barcode' => 'BC-SHARED', 'document_identifier' => 'D2']), $admin->id);
        $count = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'BC-SHARED')->count();
        expect($count)->toBe(1);
    });
});
