<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * End-to-end verification of the two-level location/disinfestation model through
 * the REAL import path (not just the model accessors): a box is imported with a
 * location and a disinfestation date; documents imported into that box WITHOUT
 * their own values must inherit the box's on the page (effective* accessors),
 * and a document imported WITH its own values must override.
 *
 * This is the "does importing actually behave" check — the box template still
 * carries a `location` column, the documents template does not, and the
 * inheritance is resolved at read time.
 */
uses(RefreshDatabase::class);

function tl_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'tl+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 * @param class-string $importer
 */
function tl_import(string $importer, array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'in.xlsx',
        'file_path' => '/tmp/in.xlsx',
        'importer' => $importer,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new $importer($row, $map, []))($data);
}

it('imports a box with a location + date, then a document that inherits both from it', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = tl_admin($repo->id);
    $this->actingAs($u);

    Series::create(['code' => 'REG', 'title' => 'Registers']);
    // A location the box lives in.
    $shelf = Location::factory()->create(['code' => 'SHELF-A1', 'repository_id' => $repo->id]);

    // Import a RAS box carrying that location and a disinfestation date.
    tl_import(BoxImporter::class, [
        'box_number' => '1',
        'box_type' => 'RAS',
        'batch_number' => '10',
        'barcode' => 'BOX-BARCODE-1',
        'barcode_status' => 'IN',
        'disinfestation_date' => '2024-05-01',
        'location' => 'SHELF-A1',
        'repository_code' => 'NRA',
    ], $u->id);

    /** @var Box $box */
    $box = Box::withoutGlobalScope(RepositoryScope::class)->where('barcode', 'BOX-BARCODE-1')->firstOrFail();
    expect($box)->not->toBeNull()
        ->and($box->location_id)->toBe($shelf->id)
        ->and($box->disinfestation_date?->toDateString())->toBe('2024-05-01');

    // Import a document INTO that box, with NO location/disinfestation of its own.
    tl_import(DocumentImporter::class, [
        'identifier' => 'DOC-INHERIT',
        'current_box_barcode' => 'BOX-BARCODE-1',
        'series' => 'REG',
        'document_type' => 'Register Volume',
        'repository_code' => 'NRA',
    ], $u->id);

    /** @var Document $doc */
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-INHERIT')->firstOrFail();
    expect($doc)->not->toBeNull()
        ->and($doc->current_box_id)->toBe($box->id)
        ->and($doc->location_id)->toBeNull()       // no own location…
        ->and($doc->disinfestation_date)->toBeNull(); // …no own date

    // …but the effective (page-visible) values come from the box.
    expect($doc->effectiveLocation()?->id)->toBe($shelf->id)
        ->and($doc->locationIsInherited())->toBeTrue()
        ->and($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2024-05-01')
        ->and($doc->disinfestationDateIsInherited())->toBeTrue();
});

it('a document imported into a box inherits its location, then a manual edit overrides it', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = tl_admin($repo->id);
    $this->actingAs($u);

    Series::create(['code' => 'REG', 'title' => 'Registers']);
    $shelf = Location::factory()->create(['code' => 'SHELF-B2', 'repository_id' => $repo->id]);
    $museum = Location::factory()->create(['code' => 'MUSEUM-1', 'repository_id' => $repo->id]);

    tl_import(BoxImporter::class, [
        'box_number' => '2',
        'box_type' => 'RAS',
        'batch_number' => '11',
        'barcode' => 'BOX-BARCODE-2',
        'barcode_status' => 'IN',
        'location' => 'SHELF-B2',
        'repository_code' => 'NRA',
    ], $u->id);

    /** @var Box $box */
    $box = Box::withoutGlobalScope(RepositoryScope::class)->where('barcode', 'BOX-BARCODE-2')->firstOrFail();

    // The document is imported into the box with no location of its own (the
    // Documents template has no location column by design), so it inherits the
    // box's location. The override is then applied by hand, as on the form.
    tl_import(DocumentImporter::class, [
        'identifier' => 'DOC-OVERRIDE',
        'current_box_barcode' => 'BOX-BARCODE-2',
        'series' => 'REG',
        'document_type' => 'Register Volume',
        'repository_code' => 'NRA',
    ], $u->id);

    /** @var Document $doc */
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'DOC-OVERRIDE')->firstOrFail();
    expect($doc->location_id)->toBeNull()                    // NOT imported
        ->and($doc->effectiveLocation()?->id)->toBe($shelf->id) // inherits the box
        ->and($doc->locationIsInherited())->toBeTrue();

    // The override is the manual, per-document edit the form provides.
    $doc->update(['location_id' => $museum->id]);
    $doc->refresh();
    expect($doc->effectiveLocation()?->id)->toBe($museum->id)
        ->and($doc->locationIsInherited())->toBeFalse()
        ->and($box->location_id)->toBe($shelf->id); // box unchanged
});
