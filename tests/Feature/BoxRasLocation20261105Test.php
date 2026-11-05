<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Client 2026-11-05, issue 8: "If a Box has Barcode Status IN, we should
 * automatically set Location as RAS (I included the option)".
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->user = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->user);
    $this->batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $this->ras = qf_location($this->repo->id, ['name' => 'RAS', 'type' => 'external']);
});

function ras_box(array $attrs = []): Box
{
    return Box::factory()->create(array_merge(['batch_id' => test()->batch->id], $attrs));
}

it('gives a RAS box created at IN the location RAS', function (): void {
    expect(ras_box()->location_id)->toBe($this->ras->id);
});

it('counts a box created without a status as IN (the column default)', function (): void {
    $box = Box::create(['box_type' => 'RAS', 'box_number' => '5', 'batch_id' => $this->batch->id]);

    expect($box->location_id)->toBe($this->ras->id);
});

it('gives the location RAS on the change to IN, with a history row', function (): void {
    $box = ras_box(['barcode_status' => 'OUT']);
    expect($box->location_id)->toBeNull();

    $box->update(['barcode_status' => 'IN']);

    expect($box->fresh()->location_id)->toBe($this->ras->id)
        ->and($box->locationHistory()->where('to_location_id', $this->ras->id)->exists())->toBeTrue();
});

it('leaves a location set by hand on a box already IN, through unrelated saves', function (): void {
    $archive = qf_location($this->repo->id, ['name' => 'Archive 1', 'type' => 'repository']);
    $box = ras_box();

    $box->update(['location_id' => $archive->id]);
    $box->fresh()->update(['notes' => 'unrelated']);

    expect($box->fresh()->location_id)->toBe($archive->id);
});

it('does nothing when there is no location RAS', function (): void {
    $this->ras->delete();
    $box = ras_box(['barcode_status' => 'OUT']);

    $box->update(['barcode_status' => 'IN']);

    expect($box->fresh()->location_id)->toBeNull();
});

it('ignores an inactive RAS, a name that is not exactly RAS, and another repository', function (): void {
    $this->ras->update(['is_active' => false]);
    qf_location($this->repo->id, ['name' => 'RAS Annex']);
    qf_location($this->repo->id, ['name' => 'ras']);
    qf_location(Repository::factory()->create()->id, ['name' => 'RAS']);

    expect(ras_box()->location_id)->toBeNull();
});

it('falls back to a global RAS, preferring the one of the repository', function (): void {
    $global = qf_location(null, ['name' => 'RAS']);
    expect(ras_box()->location_id)->toBe($this->ras->id);

    $this->ras->delete();
    expect(ras_box()->location_id)->toBe($global->id);
});

it('never touches a box that is not RAS', function (string $type): void {
    $box = Box::factory()->create([
        'box_type' => $type,
        'box_number' => $type . '1',
        'batch_id' => null,
        'repository_id' => $this->repo->id,
        'provenance_unknown' => true,
        'is_legacy' => true,
        'barcode_status' => 'OUT',
    ]);
    $before = $box->location_id;

    $box->update(['barcode_status' => 'IN']);

    expect($box->fresh()->location_id)->toBe($before)
        ->and($box->fresh()->location_id)->not->toBe($this->ras->id);
})->with(['NRA', 'MAV', 'STVC']);

it('does not put a destroyed box at RAS', function (): void {
    $box = ras_box(['destroyed_at' => now(), 'barcode_status' => 'IN']);

    expect($box->location_id)->toBeNull();
});

/** @param array<string, string> $row */
function ras_import(array $row, int $userId): void
{
    $map = ImportWizard::guessColumnMap(BoxImporter::class, array_keys($row));
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new BoxImporter($import, $map, []))($row);
}

it('an import naming a location wins over the rule', function (): void {
    $archive = qf_location($this->repo->id, ['name' => 'Archive 1', 'type' => 'repository', 'code' => 'ARCH-1']);

    ras_import([
        'Batch' => (string) $this->batch->batch_number,
        'Box Number' => '11',
        'Box Type' => 'RAS',
        'Barcode' => 'AA0011',
        'Barcode status' => 'IN',
        'Location' => 'ARCH-1',
    ], $this->user->id);

    expect(Box::query()->where('barcode', 'AA0011')->sole()->location_id)->toBe($archive->id);
});

it('an import without a location follows the rule', function (): void {
    ras_import([
        'Batch' => (string) $this->batch->batch_number,
        'Box Number' => '12',
        'Box Type' => 'RAS',
        'Barcode' => 'AA0012',
        'Barcode status' => 'IN',
    ], $this->user->id);

    expect(Box::query()->where('barcode', 'AA0012')->sole()->location_id)->toBe($this->ras->id);
});

it('namedFor answers case-sensitively, whatever the collation', function (): void {
    qf_location($this->repo->id, ['name' => 'Disinfestation']);

    expect(Location::namedFor('Disinfestation', $this->repo->id))->not->toBeNull()
        ->and(Location::namedFor('disinfestation', $this->repo->id))->toBeNull();
});
