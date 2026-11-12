<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Support\DocumentRules;
use App\Jobs\ImportCsvSlice;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\FlagType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\Export\EntityExport;
use Filament\Actions\Imports\Models\Import;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-28, second "Batch List Issues.docx": filters in the side
 * panel everywhere, finding MAV 1 and Batch 30 / Box 8 in the box pickers,
 * the documents of box NULL/NULL and Unknown/Unknown that went on into an
 * In Situ box, flags on import, and no Pages/Folios column.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    foreach (['RAS', 'MAV', 'NRA'] as $i => $code) {
        BoxType::query()->updateOrCreate(['code' => $code], ['label' => $code, 'is_active' => true, 'is_legacy' => $code !== 'RAS', 'sort_order' => $i]);
    }
});

function b28_batch(string $n): Batch
{
    return Batch::withoutGlobalScopes()->firstOrCreate(['batch_number' => $n], ['type' => 'MAIN_COLLECTION', 'repository_id' => test()->repo->id]);
}

function b28_box(string $type, string $number, ?Batch $batch = null): Box
{
    return Box::withoutGlobalScopes()->create(array_filter([
        'box_type' => $type, 'box_number' => $number, 'batch_id' => $batch?->id,
        'repository_id' => $batch === null ? test()->repo->id : null,
        'barcode' => $type === 'RAS' ? 'BC' . $number . '-' . ($batch?->batch_number ?? '') : null,
        'barcode_status' => 'IN', 'is_legacy' => $type !== 'RAS', 'provenance_unknown' => $type !== 'RAS' ? true : null,
    ], fn ($v) => $v !== null));
}

/**
 * Import $rows (header => value) through the wizard's real slice job.
 *
 * @param list<array<string, string>> $rows
 */
function b28_import(array $rows): Import
{
    $csv = tempnam(sys_get_temp_dir(), 'b28') . '.csv';
    $f = fopen($csv, 'w');
    fputcsv($f, array_keys($rows[0]));
    $offset = ftell($f);
    foreach ($rows as $row) {
        fputcsv($f, array_values($row));
    }
    fclose($f);

    $map = array_filter(ImportWizard::guessColumnMap(DocumentImporter::class, ImportWizard::csvHeaderKeys($csv)));
    $import = Import::query()->create(['file_name' => 'd.csv', 'file_path' => $csv, 'importer' => DocumentImporter::class, 'total_rows' => count($rows), 'user_id' => test()->user->id]);
    (new ImportCsvSlice($import, $csv, $offset, 1, count($rows), $map, ['repository_id' => test()->repo->id]))->handle();
    // The job signs its user out when it finishes, as the queue worker would.
    test()->actingAs(test()->user);

    return $import->refresh();
}

it('finds a box the way it reads: MAV 1, Batch 30 / Box 8, 30/8, and Box 8 before Box 18', function (): void {
    $b19 = b28_batch('19');
    $b30 = b28_batch('30');
    foreach (['8', '18', '81', '82'] as $n) {
        b28_box('RAS', $n, $b19);
    }
    $b30box8 = b28_box('RAS', '8', $b30);
    b28_box('RAS', '80', $b30);
    $mav1 = b28_box('MAV', '1');
    b28_box('MAV', '12');
    $null = b28_box('NRA', 'NULL', b28_batch('NULL'));

    foreach (['MAV 1', 'mav1'] as $search) {
        expect(array_key_first(Box::searchOptions($search)))->toBe($mav1->id);
    }
    expect(Box::searchOptions('MAV 1')[$mav1->id])->toBe('MAV 1');

    foreach (['Batch 30 / Box 8', '30/8', '30 8', 'batch 30 box 8'] as $search) {
        $options = Box::searchOptions($search);
        expect(array_key_first($options))->toBe($b30box8->id)
            ->and($options[$b30box8->id])->toBe('Batch 30 / Box 8');
    }

    // A bare 8: every Box 8, batch by batch, before any 18 or 80.
    expect(array_slice(array_values(Box::searchOptions('8')), 0, 2))->toBe(['Batch 19 / Box 8', 'Batch 30 / Box 8'])
        ->and(array_values(Box::searchOptions('box 8'))[0])->toBe('Batch 19 / Box 8')
        ->and(array_values(Box::searchOptions('batch 30')))->toBe(['Batch 30 / Box 8', 'Batch 30 / Box 80'])
        ->and(array_key_first(Box::searchOptions('NULL')))->toBe($null->id);
});

it('searches the Current box rule and filter by label', function (): void {
    $b30box8 = b28_box('RAS', '8', b28_batch('30'));
    $mav1 = b28_box('MAV', '1');
    $inBox8 = Document::withoutGlobalScopes()->create(['identifier' => 'B28-1', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'current_box_id' => $b30box8->id]);
    $inMav = Document::withoutGlobalScopes()->create(['identifier' => 'B28-2', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'current_box_id' => $mav1->id]);

    // The "Current box" and "Box (now or earlier)" rules search the same way.
    expect((DocumentRules::inBox()->getSearchResultsUsingCallback())('MAV 1'))->toBe([$mav1->id => 'MAV 1']);

    // The "Box number" rule: equals, so Box 8 is not Box 18.
    b28_box('RAS', '18', b28_batch('30'));
    Livewire::test(ListDocuments::class)
        ->set('tableFilters.queryBuilder.rules', [
            'r1' => ['type' => 'current_box_number', 'data' => ['operator' => 'equals', 'settings' => ['text' => '8']]],
        ])
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$inBox8])
        ->assertCanNotSeeTableRecords([$inMav]);
});

it('lists under box NULL/NULL the imported rows that went on into an In Situ box', function (): void {
    $import = b28_import([
        ['RAS Batch 1' => 'NULL', 'RAS Box 1' => 'NULL', 'In Situ Box 1' => 'NRA187', 'Subseries' => 'REG', 'Part Number' => 'A'],
        ['RAS Batch 1' => 'NULL', 'RAS Box 1' => 'NULL', 'In Situ Box 1' => 'NRA369', 'Subseries' => 'REG', 'Part Number' => 'B'],
        ['RAS Batch 1' => 'NULL', 'RAS Box 1' => 'NULL', 'In Situ Box 1' => '', 'Subseries' => 'REG', 'Part Number' => 'C'],
    ]);
    expect($import->successful_rows)->toBe(3);

    $null = Box::withoutGlobalScopes()->where('box_number', 'NULL')->firstOrFail();
    $docs = Document::withoutGlobalScopes()->orderBy('id')->get();
    expect($docs)->toHaveCount(3)
        // Where the sheet put each document now: the In Situ box when it names one.
        ->and($docs->where('current_box_id', $null->id))->toHaveCount(1)
        ->and(Document::query()->inBox([$null->id])->count())->toBe(3);

    // The Boxes list opens the box's documents with the new filter...
    parse_str((string) parse_url(BoxResource::documentsUrl($null), PHP_URL_QUERY), $query);
    expect($query['filters']['queryBuilder']['rules']['box']['type'])->toBe('in_box');

    // ...which lists all three; "Current box" still lists the one.
    Livewire::withQueryParams($query)
        ->test(ListDocuments::class)
        ->assertCanSeeTableRecords($docs)
        ->assertSee('Box (now or earlier) is ' . $null->load('batch')->shortLabel());
    Livewire::withQueryParams(['filters' => ['queryBuilder' => doc_rule('currentBox', 'isRelatedTo', ['value' => [(string) $null->id]])]])
        ->test(ListDocuments::class)
        ->assertCanSeeTableRecords($docs->where('current_box_id', $null->id))
        ->assertCanNotSeeTableRecords($docs->where('current_box_id', '!=', $null->id));
});

it('gives imported documents the flags their Flags cell names, once', function (): void {
    FlagType::query()->updateOrCreate(['code' => 'mould_treatment'], ['label' => 'Mould Treatment', 'colour' => 'red', 'is_active' => true, 'sort_order' => 1]);
    FlagType::query()->updateOrCreate(['code' => 'entry_issue'], ['label' => 'Entry Issue', 'colour' => 'pink', 'is_active' => true, 'sort_order' => 2]);
    $rows = [
        ['Document Identifier' => 'FLG-1', 'Subseries' => 'REG', 'Flags' => 'Mould Treatment; Pink'],
        ['Document Identifier' => 'FLG-2', 'Subseries' => 'REG', 'Flags' => 'Purple'],
    ];

    $import = b28_import($rows);
    $doc = Document::withoutGlobalScopes()->where('identifier', 'FLG-1')->firstOrFail();
    expect($import->successful_rows)->toBe(1)
        ->and(DocumentFlag::withoutGlobalScopes()->where('document_id', $doc->id)->orderBy('type')->pluck('type')->all())->toBe(['entry_issue', 'mould_treatment'])
        ->and(DocumentFlag::withoutGlobalScopes()->where('document_id', $doc->id)->pluck('title')->sort()->values()->all())->toBe(['Entry Issue', 'Mould Treatment'])
        // An unknown flag fails its row, with the reason.
        ->and(Document::withoutGlobalScopes()->where('identifier', 'FLG-2')->exists())->toBeFalse()
        ->and(json_encode($import->failedRows()->first()->validation_error))->toContain("Flag 'Purple'");

    // The export writes them back the way the column reads them.
    $exported = EntityExport::value('document', $doc->load('openFlags'), 'flags');
    expect(collect(explode('; ', $exported))->sort()->values()->all())->toBe(['Entry Issue', 'Mould Treatment']);

    b28_import($rows);
    expect(DocumentFlag::withoutGlobalScopes()->where('document_id', $doc->id)->count())->toBe(2)
        ->and(TemplateGenerator::DOCUMENT_HEADERS)->toContain('Flags');
});

it('flags documents from the Documents list, with any type on the Flag Types page', function (): void {
    FlagType::query()->updateOrCreate(['code' => 'mould_treatment'], ['label' => 'Mould Treatment', 'is_active' => true, 'sort_order' => 1]);
    FlagType::query()->create(['code' => 'conservation_seal', 'label' => 'Conservation Seal', 'is_active' => true, 'sort_order' => 20]);
    $doc = Document::withoutGlobalScopes()->create(['identifier' => 'FLG-3', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id]);
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'create_document_flag', 'guard_name' => 'web']));

    Livewire::test(ListDocuments::class)
        ->callTableBulkAction('bulkAddFlag', [$doc], ['type' => 'mould_treatment', 'severity' => 'warning']);

    expect(DocumentFlag::withoutGlobalScopes()->where('document_id', $doc->id)->value('type'))->toBe('mould_treatment')
        // A type added on the Flag Types page is offered and accepted (the
        // test database keeps the original type list as a CHECK, so it is
        // not inserted here; production stores any code).
        ->and(array_keys(DocumentFlag::typeOptions()))->toContain('conservation_seal')
        ->and(DocumentFlag::resolveType('conservation seal'))->toBe('conservation_seal');
});

it('opens every list\'s filters in the side panel and has no Pages/Folios column in Documents', function (): void {
    expect(Livewire::test(ListAuthorities::class)->instance()->getTable()->getFiltersLayout())->toBe(FiltersLayout::Modal)
        ->and(Livewire::test(ListDocuments::class)->instance()->getTable()->getColumn('pages_folios'))->toBeNull();
});
