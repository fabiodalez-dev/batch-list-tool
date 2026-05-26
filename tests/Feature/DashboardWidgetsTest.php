<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Widgets\DocumentsPerBatchChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\PendingDisinfestationTable;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * PR #11 — Dashboard widgets, demo seeder, export action.
 *
 * Strategy
 * --------
 *   - DatabaseTransactions (project uses MySQL in dev — same convention as
 *     the existing SecurityBaseline tests).
 *   - Each test builds the fixtures it needs INSIDE its own transaction so
 *     the assertions are deterministic regardless of dev-seed state.
 *   - Where the widget reads via Document::query(), we bypass the global
 *     RepositoryScope at fixture-creation time and then act as the right
 *     user to exercise the scope.
 */

uses(DatabaseTransactions::class);

/* -------------------------------------------------------------------------
 |  Helpers
 * ------------------------------------------------------------------------- */

function ensureRolesExist(): void
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'editor',      'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'viewer',      'guard_name' => 'web']);
}

function makeRepository(string $prefix): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function makeSeries(string $code = 'TST'): Series
{
    return Series::firstOrCreate(
        ['code' => $code . '_' . substr(uniqid(), -4)],
        ['title' => $code . ' series', 'is_active' => true],
    );
}

function makeDocument(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier'    => 'DOC-' . substr(uniqid(), -8),
        'document_type' => 'TEST',
        'series_id'     => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function makeBatch(int $repoId, int $batchNumber, array $attrs = []): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'batch_number'  => $batchNumber,
        'type'          => $batchNumber <= 29 ? 'MAIN_COLLECTION' : 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active'     => true,
    ], $attrs));
}

function adminUser(): User
{
    ensureRolesExist();
    $u = User::factory()->create([
        'email'     => 'admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');
    return $u;
}

function editorUserIn(Repository $repo): User
{
    ensureRolesExist();
    $u = User::factory()->create([
        'email'                 => 'editor+' . uniqid() . '@test.local',
        'is_active'             => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('editor');
    $u->repositories()->attach($repo->id, ['is_default' => true]);
    return $u;
}

beforeEach(function () {
    Cache::flush(); // widget counts are cached — give every test a clean slate
});

/* -------------------------------------------------------------------------
 |  StatsOverviewWidget
 * ------------------------------------------------------------------------- */

test('StatsOverviewWidget renders without error', function () {
    $this->actingAs(adminUser());

    Livewire::test(StatsOverviewWidget::class)->assertOk();
});

test('Documents stat returns expected count', function () {
    $repo = makeRepository('S1');
    $series = makeSeries('S1');
    makeDocument($repo->id, $series->id);
    makeDocument($repo->id, $series->id);
    makeDocument($repo->id, $series->id);

    $this->actingAs(adminUser());

    $widget = new StatsOverviewWidget();
    $reflection = (new ReflectionClass($widget))->getMethod('computeStats');
    $reflection->setAccessible(true);
    $stats = $reflection->invoke($widget);

    expect($stats['documents_total'])->toBeGreaterThanOrEqual(3);
});

test('Pending disinfestation stat counts only NULL disinfestation_date and non-PERM_OUT', function () {
    $repo = makeRepository('PD');
    $series = makeSeries('PD');
    $batch = makeBatch($repo->id, 1);

    // 3 disinfested, 2 not, 1 PERM_OUT (excluded from pending)
    $box = Box::create([
        'box_type' => 'RAS', 'box_number' => 'B-' . substr(uniqid(), -4),
        'batch_id' => $batch->id, 'barcode_status' => 'IN',
    ]);
    $permBox = Box::create([
        'box_type' => 'RAS', 'box_number' => 'B-' . substr(uniqid(), -4),
        'batch_id' => $batch->id, 'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        makeDocument($repo->id, $series->id, [
            'disinfestation_date' => now(), 'current_box_id' => $box->id,
        ]);
    }
    for ($i = 0; $i < 2; $i++) {
        makeDocument($repo->id, $series->id, [
            'disinfestation_date' => null, 'current_box_id' => $box->id,
        ]);
    }
    makeDocument($repo->id, $series->id, [
        'disinfestation_date' => null, 'current_box_id' => $permBox->id,
    ]);

    // Act as an editor scoped to the same repo
    $this->actingAs(editorUserIn($repo));

    $widget = new StatsOverviewWidget();
    $m = (new ReflectionClass($widget))->getMethod('computeStats');
    $m->setAccessible(true);
    $stats = $m->invoke($widget);

    expect($stats['pending_disinfestation'])->toBe(2);
});

test('Pending disinfestation stat respects multi-tenant scope', function () {
    $repoA = makeRepository('TA');
    $repoB = makeRepository('TB');
    $series = makeSeries('TT');

    // 4 pending in repoA, 7 pending in repoB
    for ($i = 0; $i < 4; $i++) {
        makeDocument($repoA->id, $series->id, ['disinfestation_date' => null]);
    }
    for ($i = 0; $i < 7; $i++) {
        makeDocument($repoB->id, $series->id, ['disinfestation_date' => null]);
    }

    $this->actingAs(editorUserIn($repoA));

    $widget = new StatsOverviewWidget();
    $m = (new ReflectionClass($widget))->getMethod('computeStats');
    $m->setAccessible(true);
    $stats = $m->invoke($widget);

    // Editor in repoA must only see repoA's pending count.
    expect($stats['pending_disinfestation'])->toBe(4);
});

test('Stat color is warning when pending > 0 and success when zero', function () {
    $repo = makeRepository('SC');
    $series = makeSeries('SC');

    // Case 1: 1 pending → warning
    makeDocument($repo->id, $series->id, ['disinfestation_date' => null]);

    $this->actingAs(editorUserIn($repo));

    $widget = new StatsOverviewWidget();
    $m = (new ReflectionClass($widget))->getMethod('getStats');
    $m->setAccessible(true);

    Cache::flush();
    $stats = $m->invoke($widget);

    /** @var \Filament\Widgets\StatsOverviewWidget\Stat $pendingCard */
    $pendingCard = collect($stats)->first(fn ($s) =>
        str_contains(strtolower((string) $s->getLabel()), 'pending'));
    expect($pendingCard)->not->toBeNull();

    $colorProp = (new ReflectionClass($pendingCard))->getProperty('color');
    $colorProp->setAccessible(true);
    expect($colorProp->getValue($pendingCard))->toBe('warning');

    // Case 2: clear them — color must flip to success
    Document::withoutGlobalScope(RepositoryScope::class)
        ->where('repository_id', $repo->id)
        ->update(['disinfestation_date' => now()]);
    Cache::flush();

    $widget2 = new StatsOverviewWidget();
    $stats2 = $m->invoke($widget2);
    $pending2 = collect($stats2)->first(fn ($s) =>
        str_contains(strtolower((string) $s->getLabel()), 'pending'));
    expect($colorProp->getValue($pending2))->toBe('success');
});

/* -------------------------------------------------------------------------
 |  DocumentsPerSeriesChart
 * ------------------------------------------------------------------------- */

test('DocumentsPerSeriesChart returns data keyed by series code', function () {
    $repo = makeRepository('CH');
    $sA = Series::create(['code' => 'CHA_' . substr(uniqid(), -3), 'title' => 'A', 'is_active' => true]);
    $sB = Series::create(['code' => 'CHB_' . substr(uniqid(), -3), 'title' => 'B', 'is_active' => true]);
    makeDocument($repo->id, $sA->id);
    makeDocument($repo->id, $sA->id);
    makeDocument($repo->id, $sB->id);

    $this->actingAs(adminUser());

    $widget = new DocumentsPerSeriesChart();
    $m = (new ReflectionClass($widget))->getMethod('getData');
    $m->setAccessible(true);
    $data = $m->invoke($widget);

    expect($data)->toHaveKeys(['datasets', 'labels']);
    expect($data['labels'])->toContain($sA->code, $sB->code);

    // sA must have 2 documents (and be ordered before sB because of arsort)
    $idxA = array_search($sA->code, $data['labels'], true);
    $idxB = array_search($sB->code, $data['labels'], true);
    expect($data['datasets'][0]['data'][$idxA])->toBe(2);
    expect($data['datasets'][0]['data'][$idxB])->toBe(1);
});

/* -------------------------------------------------------------------------
 |  DocumentsPerBatchChart
 * ------------------------------------------------------------------------- */

test('DocumentsPerBatchChart top-15 ordering (descending by count)', function () {
    $repo = makeRepository('TB1');
    $series = makeSeries('TB1');

    // Create 17 batches each with i+1 documents so we can verify ordering & top-15
    for ($i = 0; $i < 17; $i++) {
        $batch = makeBatch($repo->id, 100 + $i);  // skip forbidden 33/34/36
        $count = $i + 1;
        for ($j = 0; $j < $count; $j++) {
            makeDocument($repo->id, $series->id, ['batch_id' => $batch->id]);
        }
    }

    $this->actingAs(adminUser());

    $widget = new DocumentsPerBatchChart();
    $widget->filter = 'all';
    $m = (new ReflectionClass($widget))->getMethod('getData');
    $m->setAccessible(true);
    $data = $m->invoke($widget);

    // ≤ 15 entries
    expect(count($data['labels']))->toBeLessThanOrEqual(15);
    // First entry has the highest count
    $values = $data['datasets'][0]['data'];
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i])->toBeLessThanOrEqual($values[$i - 1]);
    }
});

test('DocumentsPerBatchChart filter "wills" returns only batch 50', function () {
    $repo = makeRepository('W');
    $series = makeSeries('W');

    $wills = makeBatch($repo->id, Batch::WILLS_BATCH);
    $main  = makeBatch($repo->id, 7);

    makeDocument($repo->id, $series->id, ['batch_id' => $wills->id]);
    makeDocument($repo->id, $series->id, ['batch_id' => $wills->id]);
    makeDocument($repo->id, $series->id, ['batch_id' => $main->id]);

    $this->actingAs(adminUser());

    $widget = new DocumentsPerBatchChart();
    $widget->filter = 'wills';
    $m = (new ReflectionClass($widget))->getMethod('getData');
    $m->setAccessible(true);
    $data = $m->invoke($widget);

    expect($data['labels'])->toBe(['Batch 50']);
    expect($data['datasets'][0]['data'])->toBe([2]);
});

/* -------------------------------------------------------------------------
 |  PendingDisinfestationTable
 * ------------------------------------------------------------------------- */

test('PendingDisinfestationTable renders without error', function () {
    $this->actingAs(adminUser());

    Livewire::test(PendingDisinfestationTable::class)->assertOk();
});

test('PendingDisinfestationTable markDisinfested action writes date + creates audit row', function () {
    // Auditing is suppressed in console context by default — enable it for
    // this test so we can assert the audit row is created on the action path.
    config(['audit.console' => true]);

    $repo = makeRepository('MD');
    $series = makeSeries('MD');
    $doc = makeDocument($repo->id, $series->id, ['disinfestation_date' => null]);

    $this->actingAs(adminUser());

    $beforeAudits = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->count();

    Livewire::test(PendingDisinfestationTable::class)
        ->callTableAction('markDisinfested', $doc, [
            'disinfestation_date' => now()->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    $doc->refresh();
    expect($doc->disinfestation_date?->toDateString())->toBe(now()->toDateString());

    $afterAudits = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->count();
    expect($afterAudits)->toBeGreaterThan($beforeAudits);
});

/* -------------------------------------------------------------------------
 |  RecentActivityWidget
 * ------------------------------------------------------------------------- */

test('RecentActivityWidget renders without error', function () {
    $this->actingAs(adminUser());

    Livewire::test(RecentActivityWidget::class)->assertOk();
});

test('Recent activity respects multi-tenant scope (editor cannot see audits for other repos)', function () {
    $repoA = makeRepository('RA');
    $repoB = makeRepository('RB');
    $series = makeSeries('RC');

    $docA = makeDocument($repoA->id, $series->id);
    $docB = makeDocument($repoB->id, $series->id);

    // Update both → both get an "updated" audit row
    Document::withoutGlobalScope(RepositoryScope::class)
        ->whereKey($docA->id)->update(['notes' => 'in repo A']);
    Document::withoutGlobalScope(RepositoryScope::class)
        ->whereKey($docB->id)->update(['notes' => 'in repo B']);

    // Make sure both audits actually got created (some "update via Builder" paths
    // bypass model events — force an event-driven update too for safety).
    $docA->refresh()->update(['notes' => 'in repo A — model event']);
    $docB->refresh()->update(['notes' => 'in repo B — model event']);

    // Act as editor in repoA only
    $this->actingAs(editorUserIn($repoA));

    $widget = new RecentActivityWidget();
    $m = (new ReflectionClass($widget))->getMethod('scopedAuditQuery');
    $m->setAccessible(true);
    /** @var \Illuminate\Database\Eloquent\Builder $q */
    $q = $m->invoke($widget);

    $ids = $q->get(['auditable_id', 'auditable_type'])
        ->where('auditable_type', Document::class)
        ->pluck('auditable_id')
        ->all();

    expect($ids)->not->toContain($docB->id);
});

/* -------------------------------------------------------------------------
 |  Export CSV
 * ------------------------------------------------------------------------- */

/**
 * Helper: invoke exportToCsv() through a fully-booted Livewire component
 * (so getFilteredTableQuery() works) and capture the streamed body + response.
 *
 * @return array{0: \Symfony\Component\HttpFoundation\StreamedResponse, 1: string}
 */
function captureCsvExport(?callable $configure = null): array
{
    $component = Livewire::test(ListDocuments::class);
    if ($configure) {
        $configure($component);
    }

    /** @var ListDocuments $page */
    $page = $component->instance();

    ob_start();
    $resp = $page->exportToCsv();
    $resp->sendContent();
    $body = ob_get_clean();

    return [$resp, ltrim($body, "\xEF\xBB\xBF")];
}

test('Export CSV streams correct headers (Content-Type + Content-Disposition)', function () {
    $this->actingAs(adminUser());

    [$resp, $_body] = captureCsvExport();

    expect($resp->headers->get('Content-Type'))->toContain('text/csv');
    expect($resp->headers->get('Content-Disposition'))->toContain('attachment');
    expect($resp->headers->get('Content-Disposition'))->toContain('.csv');
});

test('Export CSV contains the expected column order in the header row', function () {
    $repo = makeRepository('CS');
    $series = makeSeries('CS');
    makeDocument($repo->id, $series->id, ['identifier' => 'EXP-1', 'document_type' => 'TYPE_A']);

    $this->actingAs(adminUser());

    [$_resp, $body] = captureCsvExport();

    $firstLine = strtok($body, "\n");
    expect($firstLine)
        ->toContain('Identifier')
        ->toContain('Type')
        ->toContain('Creator')
        ->toContain('Series')
        ->toContain('Batch')
        ->toContain('Current box')
        ->toContain('Disinfestation date')
        ->toContain('Notes');

    // Column order assertion
    $headers = str_getcsv($firstLine);
    expect($headers)->toBe([
        'Identifier', 'Type', 'Creator(s)', 'Series', 'Batch',
        'Current box', 'Disinfestation date', 'Notes',
    ]);
});

test('Export CSV row count matches filtered query (no filter = all visible rows)', function () {
    $repo = makeRepository('RC');
    $series = makeSeries('RC');

    $admin = adminUser();
    $this->actingAs($admin);

    // Count BEFORE creating fixtures (dev db may have rows)
    $before = Document::query()->count();
    makeDocument($repo->id, $series->id, ['identifier' => 'RC-1']);
    makeDocument($repo->id, $series->id, ['identifier' => 'RC-2']);
    makeDocument($repo->id, $series->id, ['identifier' => 'RC-3']);
    $after = Document::query()->count();
    expect($after)->toBe($before + 3);

    [$_resp, $body] = captureCsvExport();

    // Count data rows (lines minus header). Use a CSV parser to be safe.
    $rows = 0;
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $rows++;
    }
    fclose($fh);

    // Header + N data rows. N must equal current visible total.
    expect($rows - 1)->toBe($after);
});

test('Export CSV respects active filters (filter by document_type returns matching subset)', function () {
    $repo = makeRepository('FT');
    $series = makeSeries('FT');

    // Unique token in identifier so we can isolate exactly the rows we want.
    $token = 'FTTOKEN' . strtoupper(substr(uniqid(), -5));
    makeDocument($repo->id, $series->id, ['identifier' => $token . '-A1', 'document_type' => 'TYPE_A']);
    makeDocument($repo->id, $series->id, ['identifier' => $token . '-A2', 'document_type' => 'TYPE_A']);
    makeDocument($repo->id, $series->id, ['identifier' => $token . '-B1', 'document_type' => 'TYPE_B']);

    $this->actingAs(adminUser());

    // tableSearch on identifier (searchable column) — isolates the 3 fixtures
    // among dev-seed rows. Combined with `B1` we further reduce to one match.
    [$_resp, $body] = captureCsvExport(function ($c) use ($token): void {
        $c->set('tableSearch', $token . '-B1');
    });

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $rows[] = $r;
    }
    fclose($fh);

    // Header + exactly the one B1 row.
    $dataRows = array_slice($rows, 1);
    expect(count($dataRows))->toBe(1);
    expect($dataRows[0][0])->toBe($token . '-B1');
    expect($dataRows[0][1])->toBe('TYPE_B');
});

/* -------------------------------------------------------------------------
 |  DemoDataSeeder
 * ------------------------------------------------------------------------- */

test('DemoDataSeeder is idempotent — re-running does not duplicate marker rows', function () {
    $repo = Repository::firstOrCreate(['code' => 'NRA'], [
        'name' => 'Notarial Registers Archive', 'is_active' => true,
    ]);
    $series = makeSeries('DS');
    $batch = makeBatch($repo->id, 21);
    $box1 = Box::create(['box_type' => 'RAS', 'box_number' => 'D1', 'batch_id' => $batch->id, 'barcode_status' => 'IN']);
    $box2 = Box::create(['box_type' => 'RAS', 'box_number' => 'D2', 'batch_id' => $batch->id, 'barcode_status' => 'IN']);
    for ($i = 0; $i < 6; $i++) {
        makeDocument($repo->id, $series->id, ['current_box_id' => $box1->id]);
    }

    Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
    $markers1 = BoxMovement::query()->where('reason', 'like', 'demo:%')->count();

    Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
    $markers2 = BoxMovement::query()->where('reason', 'like', 'demo:%')->count();

    expect($markers2)->toBe($markers1);
    expect($markers1)->toBeLessThanOrEqual(5);
});

test('DemoDataSeeder does NOT create new Documents/Authorities/Series', function () {
    $repo = Repository::firstOrCreate(['code' => 'NRA'], [
        'name' => 'Notarial Registers Archive', 'is_active' => true,
    ]);
    makeSeries('NX');

    $beforeDocs = Document::query()->withoutGlobalScope(RepositoryScope::class)->count();
    $beforeAuth = Authority::query()->count();
    $beforeSer  = Series::query()->count();

    Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

    $afterDocs = Document::query()->withoutGlobalScope(RepositoryScope::class)->count();
    $afterAuth = Authority::query()->count();
    $afterSer  = Series::query()->count();

    expect($afterDocs)->toBe($beforeDocs);
    expect($afterAuth)->toBe($beforeAuth);
    expect($afterSer)->toBe($beforeSer);
});

/* -------------------------------------------------------------------------
 |  Dashboard routes
 * ------------------------------------------------------------------------- */

test('Dashboard route returns 200 for authenticated admin', function () {
    $this->actingAs(adminUser());
    $this->get('/admin')->assertOk();
});

test('Dashboard route redirects (302) to login for a guest', function () {
    $this->get('/admin')->assertRedirect();
});

/* -------------------------------------------------------------------------
 |  CSV export — security regressions (PR #10 review)
 * ------------------------------------------------------------------------- */

test('it rejects CSV export for users without view_any_document permission', function () {
    // Build a user with NO roles → no `view_any_document` permission.
    ensureRolesExist();
    $unauthorized = User::factory()->create([
        'email'     => 'noperm+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);

    $this->actingAs($unauthorized);

    // Layer 1: the page itself denies the unauthorized user (resource policy).
    // We confirm the user truly lacks the permission — the gate the action
    // re-checks at the method level.
    expect($unauthorized->can('view_any_document'))->toBeFalse();

    // Layer 2 (defense-in-depth): even if somehow the page were reachable
    // (e.g. permission added then revoked mid-session, Livewire payload
    // tampering, direct method call from a console command), calling
    // exportToCsv() must throw a 403 HttpException — the in-method
    // `abort_unless(can('view_any_document'))` guard.
    //
    // We instantiate the page directly (no Livewire boot, since the page
    // would otherwise refuse to mount). This is the most reliable way to
    // assert the method-level guard in isolation.
    $page = new ListDocuments();

    expect(fn () => $page->exportToCsv())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Not authorized');

    // Cross-check: same call from an authorized admin returns a StreamedResponse,
    // proving the guard is not blocking everyone unconditionally.
    $this->actingAs(adminUser());
    $page2 = Livewire::test(ListDocuments::class)->instance();
    expect($page2->exportToCsv())->toBeInstanceOf(
        \Symfony\Component\HttpFoundation\StreamedResponse::class
    );
});

test('it sanitizes CSV formula injection in document fields', function () {
    $repo = makeRepository('CI');
    $series = makeSeries('CI');

    // Cover every dangerous leading char from the OWASP list:
    //   =   classic formula prefix (HYPERLINK, IMPORTXML, …)
    //   +   formula prefix
    //   -   formula prefix
    //   @   formula prefix (also DDE in older Excel)
    //   \t  tab — Excel may still interpret payload after it
    //   \r  carriage return — same risk
    $payloads = [
        'EQ_TOKEN' . substr(uniqid(), -5) => '=HYPERLINK("http://malicious.example/")',
        'PL_TOKEN' . substr(uniqid(), -5) => '+SUM(A1:A10)',
        'MI_TOKEN' . substr(uniqid(), -5) => '-2+3+cmd|" /C calc"!A0',
        'AT_TOKEN' . substr(uniqid(), -5) => '@SUM(1+9)',
        'TB_TOKEN' . substr(uniqid(), -5) => "\t=1+1",
        'CR_TOKEN' . substr(uniqid(), -5) => "\r=1+1",
    ];

    foreach ($payloads as $identifierToken => $notesPayload) {
        makeDocument($repo->id, $series->id, [
            'identifier' => $identifierToken,
            'notes'      => $notesPayload,
        ]);
    }

    // Extra row: identifier itself is a formula → must also be neutralized.
    $idPayload = '=HYPERLINK("http://malicious.example/")';
    makeDocument($repo->id, $series->id, [
        'identifier' => $idPayload,
        'notes'      => 'plain safe text',
    ]);

    $this->actingAs(adminUser());

    [$_resp, $body] = captureCsvExport();

    // Parse the CSV back. fputcsv unwraps quoting, so the leading "'" we add is
    // visible in the parsed cell value.
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $rows[] = $r;
    }
    fclose($fh);

    // Build identifier → notes map from the parsed CSV (col 0 = identifier, col 7 = notes).
    $byIdentifier = [];
    foreach (array_slice($rows, 1) as $r) {
        // After sanitization, the identifier column for the formula-prefixed row
        // will itself start with "'". Strip that for the lookup key.
        $key = isset($r[0]) ? ltrim($r[0], "'") : '';
        $byIdentifier[$key] = $r;
    }

    // Every token → its notes cell must start with "'" (neutralized).
    foreach ($payloads as $token => $payload) {
        expect($byIdentifier)->toHaveKey($token);
        $notesCell = $byIdentifier[$token][7] ?? '';
        expect($notesCell)
            ->toStartWith("'")
            ->and(substr($notesCell, 1))->toBe($payload);
    }

    // The row whose IDENTIFIER itself was a formula must have the identifier
    // cell neutralized (starts with "'") and the original payload preserved
    // after the prefix.
    expect($byIdentifier)->toHaveKey($idPayload);
    $idCell = $byIdentifier[$idPayload][0];
    expect($idCell)
        ->toStartWith("'")
        ->and(substr($idCell, 1))->toBe($idPayload);

    // Sanity: a plain ASCII identifier (no dangerous lead char) is NOT prefixed.
    // Insert one and re-check the same export pass would have left it untouched.
    // (We use the "plain safe text" row's notes column: it must NOT start with "'".)
    $safeNotes = $byIdentifier[$idPayload][7] ?? '';
    expect($safeNotes)->toBe('plain safe text');
});
