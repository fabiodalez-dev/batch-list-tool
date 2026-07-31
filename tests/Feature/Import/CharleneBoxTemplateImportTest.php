<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Charlene (NAF) uploads the boxes + disinfestation template. This exercises her
 * ACTUAL file — tests/Fixtures/box_filled_charlene.xlsx, whose header row is
 * verbatim the box_template she was given — through the real read path
 * (PhpSpreadsheet) and the real column map (ImportWizard::guessColumnMap), then
 * asserts:
 *   - boxes import with their disinfestation_date and location resolved;
 *   - a box left blank keeps a null disinfestation_date / location;
 *   - documents placed in those boxes inherit the box's disinfestation date and
 *     location (the two-level model she is about to try).
 */
uses(RefreshDatabase::class);

function ch_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'ch+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

it('imports Charlene\'s real box template (with disinfestation) and documents inherit it', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = ch_admin($repo->id);
    $this->actingAs($u);

    // Prerequisites the file references: batch "1" and location "SHELF-CH-1".
    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '1', 'repository_id' => $repo->id]);
    $shelf = Location::factory()->create(['code' => 'SHELF-CH-1', 'repository_id' => $repo->id]);
    Series::create(['code' => 'REG', 'title' => 'Registers']);

    // Read her real .xlsx the same way the streaming import does.
    $path = base_path('tests/Fixtures/box_filled_charlene.xlsx');
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $grid = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
    $headers = array_map(fn ($h): string => (string) $h, $grid[0]);
    $rows = array_slice($grid, 1);

    // The real column map the wizard derives from her header row.
    $map = ImportWizard::guessColumnMap(BoxImporter::class, $headers);

    foreach ($rows as $row) {
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
        (new BoxImporter($imp, $map, []))($data);
    }

    $boxes = Box::withoutGlobalScope(RepositoryScope::class)->get()->keyBy('barcode');

    // Row 1 — RAS box with disinfestation_date + location.
    expect($boxes['CH-BOX-9001']->disinfestation_date?->toDateString())->toBe('2024-05-01')
        ->and($boxes['CH-BOX-9001']->location_id)->toBe($shelf->id);

    // Row 2 — location but no disinfestation.
    expect($boxes['CH-BOX-9002']->disinfestation_date)->toBeNull()
        ->and($boxes['CH-BOX-9002']->location_id)->toBe($shelf->id);

    // Row 3 — neither.
    expect($boxes['CH-BOX-9003']->disinfestation_date)->toBeNull()
        ->and($boxes['CH-BOX-9003']->location_id)->toBeNull();

    // A document placed in the disinfested box inherits both.
    EntityResolver::flushMemo();
    /** @var Import $dimp */
    $dimp = Import::query()->create([
        'completed_at' => null, 'file_name' => 'doc.xlsx', 'file_path' => '/tmp/doc.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $u->id,
    ]);
    $docData = ['identifier' => 'CH-DOC-1', 'current_box_barcode' => 'CH-BOX-9001', 'series' => 'REG', 'document_type' => 'Register Volume', 'repository_code' => 'NRA'];
    (new DocumentImporter($dimp, array_combine(array_keys($docData), array_keys($docData)), []))($docData);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'CH-DOC-1')->firstOrFail();
    expect($doc->location_id)->toBeNull()
        ->and($doc->disinfestation_date)->toBeNull()
        ->and($doc->effectiveLocation()?->id)->toBe($shelf->id)             // inherits box location
        ->and($doc->locationIsInherited())->toBeTrue()
        ->and($doc->effectiveDisinfestationDate()?->toDateString())->toBe('2024-05-01') // inherits box date
        ->and($doc->disinfestationDateIsInherited())->toBeTrue();
});
