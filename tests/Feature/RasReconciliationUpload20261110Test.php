<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Pages\Reports\RasReconciliationReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Location;
use App\Models\RasReconciliationRow as Row;
use App\Models\RasReconciliationRun;
use App\Models\Repository;
use App\Models\User;
use App\Support\ActiveRepository;
use App\Support\Reports\RasReconciliation\RasExport;
use App\Support\Reports\RasReconciliation\Reconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-11-10, Report 1 (RAS - NRA reconciliation): "Maybe we upload the
 * report and the system matches them automatically and only shows us the
 * discrepancies?" — the RAS export (Column1 barcode, Column4 ARK/box-batch,
 * Column6 In / Out / Perm Out) compared with our RAS boxes.
 */
uses(RefreshDatabase::class);

function rr_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

function rr_batch(Repository $repo, string $number): Batch
{
    return Batch::withoutGlobalScopes()->firstOrCreate(
        ['repository_id' => $repo->id, 'batch_number' => $number],
        ['type' => 'MAIN_COLLECTION', 'is_active' => true],
    );
}

function rr_box(Repository $repo, string $batch, string $box, ?string $barcode, string $status = 'IN'): Box
{
    return Box::withoutGlobalScopes()->create([
        'box_type' => 'RAS',
        'batch_id' => rr_batch($repo, $batch)->id,
        'box_number' => $box,
        'barcode' => $barcode,
        'barcode_status' => $status,
        'is_legacy' => true,
    ]);
}

function rr_old_barcode(Box $box, string $barcode, string $status = 'PERM_OUT'): void
{
    BoxBarcodeHistory::query()->create([
        'box_id' => $box->id,
        'previous_barcode' => $barcode,
        'new_barcode' => $box->barcode,
        'previous_status' => $status,
        'new_status' => $box->barcode_status,
        'source' => 'legacy_import',
        'reason' => 'test',
    ]);
}

/**
 * A RAS export as RAS sends it: Column1..Column8.
 *
 * @param array<int, array{0: string, 1: string, 2: string}> $rows [barcode, reference, status]
 */
function rr_csv(array $rows): string
{
    $lines = ['Column1,Column2,Column3,Column4,Column5,Column6,Column7,Column8'];
    foreach ($rows as [$barcode, $reference, $status]) {
        $lines[] = "{$barcode},NATI01,,{$reference},,{$status},1/14/2019,PREMIUM";
    }
    $path = tempnam(sys_get_temp_dir(), 'ras') . '.csv';
    file_put_contents($path, implode("\n", $lines) . "\n");

    return $path;
}

/**
 * @param array<int, array{0: string, 1: string, 2: string}> $rows
 */
function rr_run(array $rows, ?Repository $repo = null, ?User $user = null): RasReconciliationRun
{
    return app(Reconciler::class)->upload(rr_csv($rows), 'csv', 'ras.csv', $repo?->id, $user?->id);
}

/** @return array<string, Row> outcome rows keyed by RAS barcode (or "ours:<barcode>") */
function rr_rows(RasReconciliationRun $run): array
{
    $out = [];
    foreach (Row::query()->where('run_id', $run->id)->orderBy('id')->get() as $row) {
        $out[$row->ras_barcode ?? 'ours:' . ($row->our_barcode ?? $row->box_id)] = $row;
    }

    return $out;
}

/* ─── Reading the export ─────────────────────────────────────────────── */

it('reads the RAS reference as box and batch, with letters, lower case and leading zeros', function (string $reference, ?array $expected): void {
    expect(RasExport::parseReference($reference))->toBe($expected);
})->with([
    'ARK/054-4 is box 54 of batch 4' => ['ARK/054-4', ['batch' => '4', 'box' => '54']],
    'ARK/999-99' => ['ARK/123-45', ['batch' => '45', 'box' => '123']],
    'ARK/9-99' => ['ARK/5-29', ['batch' => '29', 'box' => '5']],
    'box with a letter' => ['ARK/2A-29', ['batch' => '29', 'box' => '2A']],
    'box with B' => ['ARK/2b-29', ['batch' => '29', 'box' => '2B']],
    'Ark/ prefix' => ['Ark/18-37', ['batch' => '37', 'box' => '18']],
    'ark/ prefix' => ['ark/5-29', ['batch' => '29', 'box' => '5']],
    'four-digit box' => ['ARK/0113-23', ['batch' => '23', 'box' => '113']],
    'zero-padded batch' => ['ARK/7-07', ['batch' => '7', 'box' => '7']],
    'surrounding spaces' => ['  ARK/ 54 - 4 ', ['batch' => '4', 'box' => '54']],
    'not a reference' => ['BOX 54', null],
    'missing batch' => ['ARK/54', null],
]);

it('normalises our box and batch numbers the same way (054 == 54)', function (): void {
    expect(RasExport::normaliseBox('054'))->toBe('54')
        ->and(RasExport::normaliseBox('9a'))->toBe('9A')
        ->and(RasExport::normaliseBox(54))->toBe('54')
        ->and(RasExport::normaliseBox('Unknown'))->toBe('UNKNOWN')
        ->and(RasExport::normaliseBox(null))->toBeNull()
        ->and(RasExport::normaliseBatch('04'))->toBe('4')
        ->and(RasExport::normaliseBatch('NULL'))->toBe('NULL');
});

it('maps the RAS status to our barcode status', function (string $ras, ?string $ours): void {
    expect(RasExport::mapStatus($ras))->toBe($ours);
})->with([
    ['Perm Out', 'PERM_OUT'],
    ['PERM_OUT', 'PERM_OUT'],
    ['perm-out', 'PERM_OUT'],
    ['Permanently Out', 'PERM_OUT'],
    ['In', 'IN'],
    [' IN ', 'IN'],
    ['Out', 'OUT'],
    ['Lost', null],
]);

it('reads the RAS date as month/day/year', function (): void {
    expect(RasExport::parseDate('1/14/2019'))->toBe('2019-01-14')
        ->and(RasExport::parseDate('01/14/2019 12:00:00 AM'))->toBe('2019-01-14')
        ->and(RasExport::parseDate(new DateTimeImmutable('2021-12-16')))->toBe('2021-12-16')
        ->and(RasExport::parseDate(43479))->toBe('2019-01-14')
        ->and(RasExport::parseDate('14/14/2019'))->toBeNull()
        ->and(RasExport::parseDate(''))->toBeNull();
});

it('finds the columns by real header names, with ; as separator', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ras') . '.csv';
    file_put_contents($path, "Item Barcode;Client;RAS Reference;Status;Date\nAA1;NATI01;ARK/054-4;Perm Out;1/14/2019\n");

    $rows = iterator_to_array(RasExport::rows($path, 'csv'));

    expect($rows)->toHaveCount(1)
        ->and($rows[2]['barcode'])->toBe('AA1')
        ->and($rows[2]['reference'])->toBe('ARK/054-4')
        ->and($rows[2]['status'])->toBe('Perm Out');
});

it('reads a sheet with no header row', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ras') . '.csv';
    file_put_contents($path, "AA1,NATI01,,ARK/054-4,,In,1/14/2019,PREMIUM\nAA2,NATI01,,ARK/55-4,,Out,1/14/2019,PREMIUM\n");

    $rows = iterator_to_array(RasExport::rows($path, 'csv'));

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['barcode'])->toBe('AA1')
        ->and($rows[2]['status'])->toBe('Out');
});

it('refuses a file that is not the RAS export', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ras') . '.csv';
    file_put_contents($path, "Name,Surname\nJohn,Borg\n");

    iterator_to_array(RasExport::rows($path, 'csv'));
})->throws(RuntimeException::class, 'does not look like the RAS export');

/* ─── Comparison ─────────────────────────────────────────────────────── */

it('reconciles a box whose barcode, batch/box and status all match, 054 against 54', function (): void {
    $repo = Repository::factory()->create();
    $box = rr_box($repo, '4', '54', 'AA30937', 'PERM_OUT');

    $rows = rr_rows(rr_run([['AA30937', 'ARK/054-4', 'Perm Out']], $repo));

    expect($rows)->toHaveCount(1)
        ->and($rows['AA30937']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['AA30937']->box_id)->toBe($box->id)
        ->and($rows['AA30937']->ras_date?->format('Y-m-d'))->toBe('2019-01-14')
        ->and($rows['AA30937']->ras_container)->toBe('PREMIUM');
});

it('matches box numbers with a letter and zero-padded box numbers of ours', function (): void {
    $repo = Repository::factory()->create();
    rr_box($repo, '29', '2A', 'AA1', 'IN');
    rr_box($repo, '23', '0113', 'AA2', 'IN');

    $rows = rr_rows(rr_run([['AA1', 'ARK/2A-29', 'In'], ['aa2', 'ark/113-23', 'In']], $repo));

    expect($rows['AA1']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['AA2']->outcome)->toBe(Row::RECONCILED);
});

it('flags a different status and shows both', function (): void {
    $repo = Repository::factory()->create();
    rr_box($repo, '4', '54', 'AA1', 'IN');

    $row = rr_rows(rr_run([['AA1', 'ARK/054-4', 'Perm Out']], $repo))['AA1'];

    expect($row->outcome)->toBe(Row::STATUS_DIFFERS)
        ->and($row->status_mismatch)->toBeTrue()
        ->and($row->ras_status)->toBe('PERM_OUT')
        ->and($row->ras_status_raw)->toBe('Perm Out')
        ->and($row->our_status)->toBe('IN');
});

it('flags a different barcode for the same batch/box', function (): void {
    $repo = Repository::factory()->create();
    $box = rr_box($repo, '4', '54', 'AA2', 'IN');

    $rows = rr_rows(rr_run([['AA1', 'ARK/054-4', 'In']], $repo));

    expect($rows['AA1']->outcome)->toBe(Row::BARCODE_DIFFERS)
        ->and($rows['AA1']->box_id)->toBe($box->id)
        ->and($rows['AA1']->our_barcode)->toBe('AA2')
        // The box is already shown on the RAS row: not listed again as only ours.
        ->and($rows)->toHaveCount(1);
});

it('tells apart an earlier RAS barcode missing from our barcode history', function (): void {
    $repo = Repository::factory()->create();
    // Our box has the new barcode only; RAS lists the old one (Perm Out) too.
    $box = rr_box($repo, '4', '80', 'AA91128', 'IN');

    $rows = rr_rows(rr_run([['AA30928', 'ARK/080-4', 'Perm Out'], ['AA91128', 'ARK/080-4', 'In']], $repo));

    expect($rows['AA30928']->outcome)->toBe(Row::OLD_BARCODE_MISSING)
        ->and($rows['AA30928']->box_id)->toBe($box->id)
        ->and($rows['AA91128']->outcome)->toBe(Row::RECONCILED)
        ->and($rows)->toHaveCount(2);

    // Once the old barcode is in the box's history, it reconciles.
    rr_old_barcode($box, 'AA30928', 'PERM_OUT');
    $run = RasReconciliationRun::query()->sole();
    app(Reconciler::class)->compare($run);

    expect(rr_rows($run)['AA30928']->outcome)->toBe(Row::RECONCILED);
});

it('flags a different batch/box for the same barcode', function (): void {
    $repo = Repository::factory()->create();
    rr_box($repo, '4', '55', 'AA1', 'PERM_OUT');

    $row = rr_rows(rr_run([['AA1', 'ARK/054-4', 'In']], $repo))['AA1'];

    expect($row->outcome)->toBe(Row::BATCH_BOX_DIFFERS)
        ->and($row->our_batch)->toBe('4')
        ->and($row->our_box)->toBe('55')
        // The status differs too, and the "Status differs" filter finds it.
        ->and($row->status_mismatch)->toBeTrue();
});

it('lists what is only in RAS and what is only in our system', function (): void {
    $repo = Repository::factory()->create();
    rr_box($repo, '4', '54', 'AA1', 'IN');
    $ours = rr_box($repo, '9', '9', 'ZZ9', 'IN');
    $noBarcode = rr_box($repo, '9', '10', null, 'IN');

    $rows = rr_rows(rr_run([['AA1', 'ARK/054-4', 'In'], ['AB7', 'ARK/77-7', 'In']], $repo));

    expect($rows['AB7']->outcome)->toBe(Row::ONLY_IN_RAS)
        ->and($rows['AB7']->box_id)->toBeNull()
        ->and($rows['ours:ZZ9']->outcome)->toBe(Row::ONLY_IN_OURS)
        ->and($rows['ours:ZZ9']->box_id)->toBe($ours->id)
        ->and($rows['ours:' . $noBarcode->id]->outcome)->toBe(Row::ONLY_IN_OURS)
        ->and($rows['ours:' . $noBarcode->id]->note)->toBe('Our box has no barcode');
});

it('matches an earlier barcode of our box with the status it had when it was replaced', function (): void {
    $repo = Repository::factory()->create();
    // RAS: AA30928 (2019) Perm Out, then the box moved to AA91128 (2021) In.
    $box = rr_box($repo, '4', '80', 'AA91128', 'IN');
    rr_old_barcode($box, 'AA30928', 'PERM_OUT');
    $other = rr_box($repo, '4', '81', 'AA91129', 'IN');
    rr_old_barcode($other, 'AA30929', 'PERM_OUT');

    $rows = rr_rows(rr_run([
        ['AA30928', 'ARK/080-4', 'Perm Out'],
        ['AA91128', 'ARK/080-4', 'In'],
        ['AA30929', 'ARK/081-4', 'In'], // RAS still has the old barcode In
        ['AA91129', 'ARK/081-4', 'In'],
    ], $repo));

    expect($rows['AA30928']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['AA30928']->our_barcode_replaced)->toBeTrue()
        ->and($rows['AA30928']->box_id)->toBe($box->id)
        ->and($rows['AA91128']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['AA30929']->outcome)->toBe(Row::STATUS_DIFFERS)
        ->and($rows['AA30929']->our_status)->toBe('PERM_OUT')
        ->and($rows)->toHaveCount(4);
});

it('lists our box as only ours when RAS has it only under an earlier barcode', function (): void {
    $repo = Repository::factory()->create();
    $box = rr_box($repo, '4', '80', 'AA91128', 'IN');
    rr_old_barcode($box, 'AA30928', 'PERM_OUT');

    $rows = rr_rows(rr_run([['AA30928', 'ARK/080-4', 'Perm Out']], $repo));

    expect($rows['AA30928']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['ours:AA91128']->outcome)->toBe(Row::ONLY_IN_OURS)
        ->and($rows['ours:AA91128']->note)->toBe('RAS lists this box only under an earlier barcode');
});

it('compares only with the RAS boxes of the run repository', function (): void {
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    rr_box($a, '4', '54', 'AA1', 'IN');
    rr_box($b, '4', '55', 'AA2', 'IN'); // other repository: neither matched nor "only ours"
    Box::withoutGlobalScopes()->create(['box_type' => 'NRA', 'box_number' => '56', 'batch_id' => rr_batch($a, '4')->id, 'barcode' => 'AA3', 'barcode_status' => 'IN', 'is_legacy' => true, 'provenance_unknown' => true, 'location_id' => Location::factory()->create()->id]);

    $run = rr_run([['AA1', 'ARK/054-4', 'In'], ['AA2', 'ARK/055-4', 'In'], ['AA3', 'ARK/056-4', 'In']], $a);
    $rows = rr_rows($run);

    expect($rows['AA1']->outcome)->toBe(Row::RECONCILED)
        ->and($rows['AA2']->outcome)->toBe(Row::ONLY_IN_RAS)
        // An NRA box is not one of our RAS boxes.
        ->and($rows['AA3']->outcome)->toBe(Row::ONLY_IN_RAS)
        ->and($rows)->toHaveCount(3)
        ->and($run->our_boxes)->toBe(1);
});

it('keeps only the latest upload per repository', function (): void {
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $first = rr_run([['AA1', 'ARK/054-4', 'In']], $a);
    $other = rr_run([['AA1', 'ARK/054-4', 'In']], $b);
    $second = rr_run([['AA2', 'ARK/055-4', 'In']], $a);

    expect(RasReconciliationRun::query()->pluck('id')->all())->toEqualCanonicalizing([$other->id, $second->id])
        ->and(Row::query()->where('run_id', $first->id)->count())->toBe(0);
});

it('compares again after our records are fixed', function (): void {
    $repo = Repository::factory()->create();
    $box = rr_box($repo, '4', '54', 'AA1', 'IN');
    $run = rr_run([['AA1', 'ARK/054-4', 'Perm Out']], $repo);
    expect(rr_rows($run)['AA1']->outcome)->toBe(Row::STATUS_DIFFERS);

    $box->barcode_status = 'PERM_OUT';
    $box->skipPermOutGuard = true;
    $box->save();
    app(Reconciler::class)->compare($run);

    expect(rr_rows($run->fresh())['AA1']->outcome)->toBe(Row::RECONCILED)
        ->and(Row::query()->where('run_id', $run->id)->count())->toBe(1);
});

it('reconciles the 300-row sample of the RAS export when our boxes hold the same data', function (): void {
    $repo = Repository::factory()->create();
    $path = base_path('tests/Fixtures/ras_report_sample.xlsx');

    // Build our boxes from the sample itself: per batch/box the last RAS row
    // is the current barcode, the earlier ones its barcode history.
    $groups = [];
    foreach (RasExport::rows($path, 'xlsx') as $cells) {
        $key = RasExport::parseReference($cells['reference']);
        expect($key)->not->toBeNull();
        $groups[$key['batch'] . '|' . $key['box']][] = [(string) $cells['barcode'], RasExport::mapStatus($cells['status'])];
    }
    $total = array_sum(array_map('count', $groups));
    expect($total)->toBe(300);

    $boxes = [];
    foreach ($groups as $key => $barcodes) {
        [$batch, $number] = explode('|', $key);
        [$current, $status] = array_pop($barcodes);
        $box = rr_box($repo, $batch, $number, $current, (string) $status);
        foreach ($barcodes as [$old, $oldStatus]) {
            rr_old_barcode($box, $old, (string) $oldStatus);
        }
        $boxes[$key] = $box;
    }

    $run = app(Reconciler::class)->upload($path, 'xlsx', 'RAS_Sample.xlsx', $repo->id, null);
    $summary = Reconciler::summary($run);

    expect($run->ras_rows)->toBe(300)
        ->and($summary[Row::RECONCILED])->toBe(300)
        ->and($summary[Row::ONLY_IN_OURS])->toBe(0);

    // Two mistakes on our side, one extra box.
    $first = array_values($boxes)[0];
    $first->barcode_status = $first->barcode_status === 'IN' ? 'OUT' : 'IN';
    $first->saveQuietly();
    $second = array_values($boxes)[1];
    $second->box_number = '9999';
    $second->saveQuietly();
    rr_box($repo, '1', '1', 'ZZ00001', 'IN');

    app(Reconciler::class)->compare($run);
    $summary = Reconciler::summary($run);

    expect($summary[Row::STATUS_DIFFERS])->toBe(1)
        ->and($summary[Row::BATCH_BOX_DIFFERS])->toBeGreaterThanOrEqual(1)
        ->and($summary[Row::ONLY_IN_OURS])->toBe(1)
        ->and($summary[Row::ONLY_IN_RAS])->toBe(0);
});

/* ─── The page ───────────────────────────────────────────────────────── */

it('shows an empty state before the first upload', function (): void {
    $this->actingAs(rr_admin());

    Livewire::test(RasReconciliationReport::class)
        ->assertOk()
        ->assertSee('No RAS report has been uploaded');
});

it('uploads the RAS report from the page and records who uploaded it', function (): void {
    $user = rr_admin();
    $this->actingAs($user);
    $repo = Repository::factory()->create();
    app(ActiveRepository::class)->set($repo->id);
    rr_box($repo, '4', '54', 'AA1', 'IN');

    $csv = file_get_contents(rr_csv([['AA1', 'ARK/054-4', 'In'], ['AB7', 'ARK/77-7', 'Perm Out']]));

    Livewire::test(RasReconciliationReport::class)
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('RAS export.csv', (string) $csv)])
        ->assertHasNoActionErrors();

    $run = RasReconciliationRun::query()->sole();
    expect($run->repository_id)->toBe($repo->id)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->original_filename)->toBe('RAS export.csv')
        ->and($run->ras_rows)->toBe(2);

    Livewire::test(RasReconciliationReport::class)
        ->assertSee('RAS export.csv')
        ->assertSee($user->name);
});

it('hides reconciled rows by default and shows them with the toggle off', function (): void {
    $this->actingAs(rr_admin());
    $repo = Repository::factory()->create();
    app(ActiveRepository::class)->set($repo->id);
    rr_box($repo, '4', '54', 'AA1', 'IN');
    rr_box($repo, '4', '55', 'AA2', 'IN');
    $run = rr_run([['AA1', 'ARK/054-4', 'In'], ['AA2', 'ARK/055-4', 'Out'], ['AB7', 'ARK/77-7', 'In']], $repo);
    $rows = rr_rows($run);

    Livewire::test(RasReconciliationReport::class)
        ->assertCanSeeTableRecords([$rows['AA2'], $rows['AB7']])
        ->assertCanNotSeeTableRecords([$rows['AA1']])
        ->filterTable('discrepancies_only', false)
        ->assertCanSeeTableRecords([$rows['AA1'], $rows['AA2'], $rows['AB7']])
        ->filterTable('discrepancies_only', true)
        ->filterTable('outcome', [Row::ONLY_IN_RAS])
        ->assertCanSeeTableRecords([$rows['AB7']])
        ->assertCanNotSeeTableRecords([$rows['AA2']]);
});

it('does not show the run of another repository', function (): void {
    $this->actingAs(rr_admin());
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    rr_run([['AA1', 'ARK/054-4', 'In']], $a);

    app(ActiveRepository::class)->set($b->id);

    Livewire::test(RasReconciliationReport::class)
        ->assertSet('runId', null)
        ->assertSee('No RAS report has been uploaded');
});

it('exports what is on screen: the discrepancies', function (): void {
    $this->actingAs(rr_admin());
    $repo = Repository::factory()->create();
    app(ActiveRepository::class)->set($repo->id);
    rr_box($repo, '4', '54', 'AA1', 'IN');
    rr_run([['AA1', 'ARK/054-4', 'In'], ['AB7', 'ARK/77-7', 'In']], $repo);

    $page = Livewire::test(RasReconciliationReport::class)->instance();
    $page->bootedInteractsWithTable();
    $response = $page->exportCsv();
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('AB7')
        ->and($csv)->toContain('Only in RAS')
        ->and($csv)->not->toContain('AA1,');
});

it('links Report 1 from the reports page and keeps the document RAS-key report', function (): void {
    $this->actingAs(rr_admin());

    $cards = collect((new Reports)->cards())->keyBy('key');

    expect($cards['ras-reconciliation']['title'])->toBe('RAS ↔ NRA reconciliation')
        ->and($cards['ras-reconciliation']['url'])->toBe(RasReconciliationReport::getUrl())
        ->and($cards['ras-reconciliation']['count'])->toBe('No upload yet')
        ->and($cards['ras-nra-reconciliation']['title'])->toBe('RAS key on documents');
});
