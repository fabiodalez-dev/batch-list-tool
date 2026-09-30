<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\RelationManagers\BarcodeHistoryRelationManager as BoxBarcodeHistory;
use App\Filament\Resources\BoxResource\RelationManagers\SealNumberHistoryRelationManager;
use App\Filament\Resources\DocumentFlagResource\Pages\ListDocumentFlags;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\RelationManagers\BarcodeHistoryRelationManager as DocBarcodeHistory;
use App\Filament\Resources\DocumentResource\RelationManagers\IdentifierHistoryRelationManager;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * A filter closure only works if its query parameter is NAMED $query.
 *
 * Filament passes the table query by name (`'query' => $query`) and discards
 * whatever the closure returns. A closure written `fn (Builder $q, array $data)`
 * gets a different builder resolved by type, adds its where clause to that, and
 * the table query stays unfiltered. Nothing errors: the filter shows in the form,
 * takes a value, draws its indicator — and filters nothing.
 *
 * Found 2026-10-01 in 14 closures: all five filters of the Flags-by-type report,
 * eight filters of the documents list (the six "Search in …" filters, the year
 * range and the disinfestation range), the flags list date range, and the date
 * range of the four history tabs.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');
    $this->actingAs($u);
});

function fcq_sql(Testable $component): string
{
    return $component->instance()->getFilteredTableQuery()->toRawSql();
}

function fcq_document(): Document
{
    $repo = Repository::factory()->create(['code' => 'FCQ']);
    $series = Series::firstOrCreate(['code' => 'FCQ'], ['title' => 'F', 'is_active' => true]);

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'FCQ-1', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
}

it('narrows the documents list by a "Search in" filter, row for row', function (): void {
    $repo = Repository::factory()->create(['code' => 'FCQD']);
    $series = Series::firstOrCreate(['code' => 'FCQD'], ['title' => 'F', 'is_active' => true]);
    foreach (['CAT-KEEP-1', 'CAT-DROP-2'] as $i => $cat) {
        Document::withoutGlobalScope(RepositoryScope::class)->create([
            'identifier' => "FCQD-{$i}", 'catalogue_identifier' => $cat, 'document_type' => 'T',
            'series_id' => $series->id, 'repository_id' => $repo->id,
        ]);
    }

    $filtered = Livewire::test(ListDocuments::class)
        ->filterTable('catalogue_identifier', ['value' => 'KEEP'])
        ->instance()->getFilteredTableQuery()->pluck('catalogue_identifier')->all();

    expect($filtered)->toBe(['CAT-KEEP-1']);
});

it('applies every repaired documents-list filter to the query', function (string $filter, array $value, string $expect): void {
    $sql = fcq_sql(Livewire::test(ListDocuments::class)->filterTable($filter, $value));

    expect($sql)->toContain($expect);
})->with([
    'search in catalogue identifier' => ['catalogue_identifier', ['value' => 'ZQX91'], 'ZQX91'],
    'search in practice' => ['practice', ['value' => 'ZQX92'], 'ZQX92'],
    'search in barcode in' => ['barcode_in', ['value' => 'ZQX93'], 'ZQX93'],
    'search in notes' => ['notes', ['value' => 'ZQX94'], 'ZQX94'],
    'search in museum reference' => ['museum_reference', ['value' => 'ZQX95'], 'ZQX95'],
    'search in deeds' => ['deeds', ['value' => 'ZQX96'], 'ZQX96'],
    'year range' => ['year_range', ['year_from' => 1789], 'dates_year_end'],
    'disinfestation range' => ['disinfestation_range', ['disinfested_from' => '2026-01-01'], '2026-01-01'],
]);

it('applies the flags-list date range to the query', function (): void {
    $sql = fcq_sql(Livewire::test(ListDocumentFlags::class)->filterTable('flagged_at_range', ['from' => '2026-02-03']));

    expect($sql)->toContain('2026-02-03');
});

it('applies every Flags-by-type report filter to the query', function (string $filter, mixed $value, string $expect): void {
    $sql = fcq_sql(Livewire::test(FlagsByTypeReport::class)->filterTable($filter, $value));

    expect($sql)->toContain($expect);
})->with([
    'type' => ['type', 'needs_review', "'needs_review'"],
    'severity' => ['severity', 'warning', "'warning'"],
    'status' => ['status', 'open', 'document_flags"."status'],
    'repository' => ['repository_id', 987654, '987654'],
    'date range' => ['date_range', ['from' => '2026-04-05'], '2026-04-05'],
]);

it('applies the date range on the document history tabs', function (string $manager): void {
    $doc = fcq_document();

    $sql = fcq_sql(
        Livewire::test($manager, ['ownerRecord' => $doc, 'pageClass' => EditDocument::class])
            ->filterTable('changed_at_range', ['from' => '2026-06-07'])
    );

    expect($sql)->toContain('2026-06-07');
})->with([
    'barcode history' => [DocBarcodeHistory::class],
    'identifier history' => [IdentifierHistoryRelationManager::class],
]);

it('applies the date range on the box history tabs', function (string $manager): void {
    $repo = Repository::factory()->create(['code' => 'FCQB']);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9301', 'type' => 'NOTARY_ACCESSION', 'repository_id' => $repo->id, 'is_active' => true]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => 'FQ1', 'batch_id' => $batch->id, 'barcode' => 'FQAA0001', 'barcode_status' => 'IN', 'is_legacy' => false]);

    $sql = fcq_sql(
        Livewire::test($manager, ['ownerRecord' => $box, 'pageClass' => EditBox::class])
            ->filterTable('changed_at_range', ['from' => '2026-08-09'])
    );

    expect($sql)->toContain('2026-08-09');
})->with([
    'barcode history' => [BoxBarcodeHistory::class],
    'seal number history' => [SealNumberHistoryRelationManager::class],
]);

it('never names a filter query parameter anything but $query', function (): void {
    // The structural guard: the bug is silent at runtime, so the only reliable
    // net is refusing the spelling at the source.
    $pattern = '/->(query|modifyQueryUsing|baseQuery|modifyBaseQueryUsing)\((?:static )?(?:fn|function) ?\(([^)]*)\)/';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[2] as [$params, $offset]) {
            foreach (explode(',', $params) as $param) {
                if (str_contains($param, 'Builder') && ! preg_match('/\$query\b/', $param)) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ":{$line}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
