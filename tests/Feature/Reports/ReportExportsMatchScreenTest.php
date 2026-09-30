<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DisinfestationCycleReport;
use App\Filament\Pages\Reports\DocumentLocationReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Pages\Reports\RasNraReconciliationReport;
use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Every report export must contain exactly the rows the operator is looking at.
 *
 * Driven through the real entry point: the report page is mounted as a
 * Livewire component, a filter is applied the way the filter form applies it,
 * and the export button is pressed. The downloaded file is then read back —
 * CSV parsed, XLSX opened, PDF rows taken from the view that renders it — and
 * its row count compared with the filtered table on screen.
 *
 * Until 2026-10-01 four reports ignored the filters in every format, and four
 * more honoured them in XLSX but not in CSV or PDF. The second assertion of
 * every case (the filter really narrows the data) is what keeps this honest:
 * without it, a filter that matched everything would make a broken export look
 * correct.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');
    $this->actingAs($u);
    $this->world = rex_world($u);
});

/**
 * Two repositories whose data every report can tell apart.
 *
 * @return array<string, int>
 */
function rex_world(User $u): array
{
    $a = Repository::factory()->create(['code' => 'REXA']);
    $b = Repository::factory()->create(['code' => 'REXB']);
    $sA = Series::create(['code' => 'REXSA', 'title' => 'Series A', 'is_active' => true]);
    $sB = Series::create(['code' => 'REXSB', 'title' => 'Series B', 'is_active' => true]);

    $bA = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9101', 'type' => 'NOTARY_ACCESSION', 'repository_id' => $a->id, 'is_active' => true]);
    $bB = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9102', 'type' => 'MAIN_COLLECTION', 'repository_id' => $b->id, 'is_active' => true]);

    $boxA = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => 'RX1', 'batch_id' => $bA->id, 'barcode' => 'RXAA0001', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $boxB = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'IN_SITU', 'box_number' => 'RX2', 'batch_id' => $bB->id, 'barcode_status' => 'IN', 'is_legacy' => false, 'provenance_unknown' => true]);

    $doc = fn (array $attrs): Document => Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge(['document_type' => 'T'], $attrs));

    // A: one reconcilable document (full RAS key). B: two that are not.
    $dA1 = $doc(['identifier' => 'REX-A1', 'repository_id' => $a->id, 'series_id' => $sA->id, 'batch_id' => $bA->id, 'current_box_id' => $boxA->id, 'ras_batch_1' => '5', 'ras_box_1' => '7', 'barcode_in' => 'AA000001']);
    $dB1 = $doc(['identifier' => 'REX-B1', 'repository_id' => $b->id, 'series_id' => $sB->id, 'batch_id' => $bB->id, 'current_box_id' => $boxB->id, 'ras_batch_1' => '6']);
    $dB2 = $doc(['identifier' => 'REX-B2', 'repository_id' => $b->id, 'series_id' => $sB->id, 'batch_id' => $bB->id, 'current_box_id' => $boxB->id, 'ras_batch_1' => '6']);

    $auA = Authority::create(['identifier' => 'RX-AU-A', 'surname' => 'Alpha', 'entity_type' => 'Notary']);
    $auB = Authority::create(['identifier' => 'RX-AU-B', 'surname' => 'Beta', 'entity_type' => 'Notary']);
    $dA1->authorities()->attach($auA->id);
    $dB1->authorities()->attach($auB->id);
    $dB2->authorities()->attach($auB->id);

    DocumentFlag::factory()->create(['document_id' => $dA1->id, 'flagged_by_user_id' => $u->id, 'type' => DocumentFlag::TYPES[0]]);
    DocumentFlag::factory()->create(['document_id' => $dB1->id, 'flagged_by_user_id' => $u->id, 'type' => DocumentFlag::TYPES[1] ?? DocumentFlag::TYPES[0]]);

    BoxMovement::create(['document_id' => $dA1->id, 'repository_id' => $a->id, 'from_box_id' => $boxA->id, 'to_box_id' => $boxB->id, 'movement_date' => now()->subDays(2), 'user_id' => $u->id]);
    BoxMovement::create(['document_id' => $dB1->id, 'repository_id' => $b->id, 'from_box_id' => $boxB->id, 'to_box_id' => $boxA->id, 'movement_date' => now()->subDay(), 'user_id' => $u->id]);

    return ['repoA' => $a->id, 'repoB' => $b->id, 'boxA' => $boxA->id];
}

/**
 * Press an export button on a mounted report and return the file's content.
 */
function rex_download(Testable $component, string $action): string
{
    $component->callAction($action);
    $download = $component->effects['download'] ?? null;

    expect($download)->not->toBeNull("pressing {$action} produced no download");

    return (string) base64_decode((string) $download['content'], true);
}

function rex_csvRows(string $content): int
{
    $content = preg_replace('/^\x{FEFF}/u', '', $content) ?? $content;
    $h = fopen('php://memory', 'r+');
    fwrite($h, $content);
    rewind($h);
    $rows = 0;
    while (($line = fgetcsv($h, escape: '\\')) !== false) {
        if ($line !== [null]) {
            $rows++;
        }
    }
    fclose($h);

    return max(0, $rows - 1); // minus the header
}

function rex_xlsxRows(string $content): int
{
    $path = tempnam(sys_get_temp_dir(), 'rex') . '.xlsx';
    file_put_contents($path, $content);
    $rows = IOFactory::load($path)->getActiveSheet()->getHighestDataRow() - 1; // minus the header
    @unlink($path);

    return max(0, $rows);
}

/**
 * Every report, the filter that narrows it, and the formats it offers.
 *
 * @return array<string, array{0: class-string, 1: string, 2: Closure(array<string,int>): mixed, 3: list<string>}>
 */
function rex_cases(): array
{
    $all = ['exportCsv', 'exportXlsx', 'exportPdf'];

    return [
        'documents by batch' => [DocumentsByBatchReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'documents by series' => [DocumentsBySeriesReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'documents by creator' => [DocumentsByCreatorReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'document location' => [DocumentLocationReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'pending disinfestation' => [PendingDisinfestationReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'flags by type' => [FlagsByTypeReport::class, 'repository_id', fn (array $w) => $w['repoA'], ['exportCsv', 'exportPdf']],
        'stock take' => [StockTakeReport::class, 'repository_id', fn (array $w) => [$w['repoA']], $all],
        'RAS / NRA reconciliation' => [RasNraReconciliationReport::class, 'reconcilable', fn (array $w) => true, $all],
        'disinfestation cycle' => [DisinfestationCycleReport::class, 'box_type', fn (array $w) => ['RAS'], $all],
        'box movement history' => [BoxMovementHistoryReport::class, 'from_box_id', fn (array $w) => [$w['boxA']], $all],
    ];
}

it('exports exactly the filtered rows on screen', function (string $report, string $filter, Closure $value, array $formats): void {
    $unfiltered = Livewire::test($report)->instance()->getFilteredTableQuery()->get()->count();

    $screen = fn (): Testable => Livewire::test($report)->filterTable($filter, $value($this->world));
    $onScreen = $screen()->instance()->getFilteredTableQuery()->get()->count();

    expect($onScreen)->toBeGreaterThan(0, "{$report}: the filter left nothing to export, so the comparison proves nothing")
        ->and($onScreen)->toBeLessThan($unfiltered, "{$report}: the filter does not narrow the seeded data, so the comparison proves nothing");

    foreach ($formats as $action) {
        $pdfRows = null;
        View::composer('reports.pdf-layout', function ($view) use (&$pdfRows): void {
            $pdfRows = count($view->getData()['rows'] ?? []);
        });

        $content = rex_download($screen(), $action);

        $inFile = match ($action) {
            'exportCsv' => rex_csvRows($content),
            'exportXlsx' => rex_xlsxRows($content),
            'exportPdf' => $pdfRows,
        };

        expect($inFile)->toBe($onScreen, "{$report} {$action}: {$inFile} rows in the file, {$onScreen} on screen");
    }
})->with(rex_cases());
