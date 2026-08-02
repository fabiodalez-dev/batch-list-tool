<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * NAF client feedback (Charlene, 2026-08-01) — the first legacy box import hit
 * 721 failures. This drives the sanitized, PII-free fixture built from her real
 * failing cases (tests/Fixtures/box_legacy_cases.xlsx) through the real read
 * path (PhpSpreadsheet) + real column map (ImportWizard::guessColumnMap), and
 * asserts every loosened rule + the two new columns (Destroyed, Current Box
 * Type). The full real 7166-row file is exercised locally by
 * BoxRealFileImportSmokeTest (skipped in CI where the PII file is absent).
 */
uses(RefreshDatabase::class);

/**
 * Import the fixture and return the created boxes keyed by box_number.
 *
 * @return Collection<string, Box>
 */
function blc_import(): Collection
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id, 'email' => 'blc+' . uniqid() . '@test.local']);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => '1', 'repository_id' => $repo->id],
    );
    Location::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['code' => 'SHELF-1', 'repository_id' => $repo->id],
        ['name' => 'Shelf 1', 'type' => 'repository', 'is_active' => true],
    );

    $path = base_path('tests/Fixtures/box_legacy_cases.xlsx');
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $grid = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
    $headers = array_map(fn ($h): string => (string) $h, $grid[0]);
    $map = ImportWizard::guessColumnMap(BoxImporter::class, $headers);

    foreach (array_slice($grid, 1) as $row) {
        if (count(array_filter($row, fn ($c): bool => $c !== null && trim((string) $c) !== '')) === 0) {
            continue;
        }
        $data = [];
        foreach ($headers as $i => $h) {
            $data[$h] = $row[$i] ?? null;
        }
        EntityResolver::flushMemo();
        /** @var Import $imp */
        $imp = Import::query()->create([
            'completed_at' => null, 'file_name' => 'box.xlsx', 'file_path' => '/tmp/box.xlsx',
            'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
            'successful_rows' => 0, 'user_id' => $u->id,
        ]);

        try {
            (new BoxImporter($imp, $map, []))($data);
        } catch (Throwable) {
            // A row that fails validation (e.g. IN_SITU without a parent) is
            // skipped, exactly as the streaming importer does — the box simply
            // isn't created and the test asserts its absence.
        }
    }

    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->get()->keyBy('box_number');
}

// ─────────────────────────── loosened: PERM_OUT ───────────────────────────

it('imports a PERM_OUT box with no location (was blocked: 675 rows)', function () {
    $b = blc_import()->get('901');
    expect($b)->not->toBeNull()
        ->and($b->location_id)->toBeNull();
});

it('imports a PERM_OUT box with no disinfestation_date (was blocked: 33 rows, RFQ #5)', function () {
    expect(blc_import()->get('901')?->disinfestation_date)->toBeNull();
});

it('imports a PERM_OUT box with no barcode', function () {
    expect(blc_import()->get('901')?->barcode)->toBeNull();
});

it('imports a PERM_OUT box that DOES have a location (no regression)', function () {
    $b = blc_import()->get('903');
    expect($b)->not->toBeNull()
        ->and($b->barcode_status)->toBe('PERM_OUT')
        ->and($b->location_id)->not->toBeNull();
});

// ─────────────────────────── loosened: barcode ───────────────────────────

it('imports a RAS box with no barcode (was blocked: 10 rows)', function () {
    $b = blc_import()->get('904');
    expect($b)->not->toBeNull()
        ->and($b->box_type)->toBe('RAS')
        ->and($b->barcode)->toBeNull();
});

it('still imports a RAS box that has a barcode', function () {
    expect(blc_import()->get('900')?->barcode)->toBe('LEG-900');
});

// ─────────────────────── loosened: box_type (Unknown/NULL) ───────────────────────

it('imports the NULL catch-all box (box_type blank, was blocked)', function () {
    $b = blc_import()->get('NULL');
    expect($b)->not->toBeNull()
        ->and($b->box_type)->toBeNull();
});

it('imports the Unknown catch-all box (box_type blank)', function () {
    expect(blc_import()->get('Unknown'))->not->toBeNull();
});

it('the NULL / Unknown boxes carry no batch (batch NULL/Unknown does not resolve)', function () {
    $boxes = blc_import();
    expect($boxes->get('NULL')?->batch_id)->toBeNull()
        ->and($boxes->get('Unknown')?->batch_id)->toBeNull();
});

// ─────────────────────────── new column: Destroyed ───────────────────────────

it('marks a box destroyed when Destroyed = Yes', function () {
    expect(blc_import()->get('902')?->destroyed_at)->not->toBeNull();
});

it('stamps destroyed_by to the importing user', function () {
    $b = blc_import()->get('902');
    expect($b?->destroyed_by_user_id)->not->toBeNull()
        ->and($b?->destroyedBy?->is_active)->toBeTrue();
});

it('records a destroyed_reason for an imported destruction', function () {
    expect(blc_import()->get('902')?->destroyed_reason)->toContain('legacy');
});

it('uses the given date when Destroyed is a date', function () {
    expect(blc_import()->get('906')?->destroyed_at?->toDateString())->toBe('2024-06-15');
});

it('does NOT mark destroyed when Destroyed = No', function () {
    expect(blc_import()->get('907')?->destroyed_at)->toBeNull();
});

it('does NOT mark destroyed when Destroyed is blank', function () {
    expect(blc_import()->get('900')?->destroyed_at)->toBeNull();
});

it('marks destroyed for a truthy value like "x"', function () {
    expect(blc_import()->get('911')?->destroyed_at)->not->toBeNull();
});

// ─────────────────────── new column: Current Box Type ───────────────────────

it('stores Current Box Type "Big Brown Box"', function () {
    expect(blc_import()->get('905')?->current_box_type)->toBe('Big Brown Box');
});

it('stores Current Box Type "Small Brown Box"', function () {
    expect(blc_import()->get('906')?->current_box_type)->toBe('Small Brown Box');
});

it('stores Current Box Type "RAS Box"', function () {
    expect(blc_import()->get('900')?->current_box_type)->toBe('RAS Box');
});

it('leaves Current Box Type null when blank', function () {
    expect(blc_import()->get('901')?->current_box_type)->toBeNull();
});

// ─────────────────── still enforced (NOT loosened) ───────────────────

it('imports an IN_SITU box with a parent and resolves the parent', function () {
    $boxes = blc_import();
    $child = $boxes->get('908');
    $parent = $boxes->get('900');
    expect($child)->not->toBeNull()
        ->and($child->parent_box_id)->toBe($parent->id);
});

it('still REJECTS an IN_SITU box with no parent (RFQ #3 unchanged)', function () {
    expect(blc_import()->get('909'))->toBeNull();
});

it('still forces is_legacy = true for a MAV box (RFQ #4 unchanged)', function () {
    expect(blc_import()->get('910')?->is_legacy)->toBeTrue();
});

it('defaults is_legacy to false for a normal box', function () {
    expect(blc_import()->get('900')?->is_legacy)->toBeFalse();
});

// ─────────────────── combined + template surface ───────────────────

it('a PERM_OUT + destroyed box needs neither location nor disinfestation (Charlene\'s core case)', function () {
    $b = blc_import()->get('902');
    expect($b)->not->toBeNull()
        ->and($b->barcode_status)->toBe('PERM_OUT')
        ->and($b->destroyed_at)->not->toBeNull()
        ->and($b->location_id)->toBeNull()
        ->and($b->disinfestation_date)->toBeNull();
});

it('the Boxes importer now exposes the Destroyed and Current Box Type columns', function () {
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName());
    expect($cols)->toContain('destroyed')
        ->and($cols)->toContain('current_box_type');
});
