<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DisinfestationCycleReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Pages\Reports\RasNraReconciliationReport;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * RFQ §3.1.10 — Report builder feature tests.
 *
 * Covers the 5 canned reports + landing page + CSV/PDF export +
 * Shield gating + caching. The tests use the same seeding helpers
 * as the rest of the suite (bl_seedShieldPermissions) so the role
 * matrix stays consistent with InitialDataSeeder.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/* ─── Helpers ──────────────────────────────────────────────────────── */

function rep_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function rep_user(string $role): User
{
    rep_seedRoles();
    $u = User::factory()->create([
        'email' => 'rep-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function rep_repo(string $prefix = 'RP'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function rep_series(string $code = 'RPS'): Series
{
    return Series::firstOrCreate(
        ['code' => $code . '_' . substr(uniqid(), -4)],
        ['title' => $code . ' series', 'is_active' => true],
    );
}

function rep_batch(int $repoId, ?int $number = null): Batch
{
    do {
        $n = $number ?? random_int(2000, 8999);
        $number = null;
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function rep_box(int $batchId): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS',
        'box_number' => 'B' . substr(uniqid(), -6),
        'batch_id' => $batchId,
        'barcode' => 'BC' . substr(uniqid(), -8),
        'barcode_status' => 'IN',
    ]);
}

function rep_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function rep_authority(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'A-' . strtoupper(substr(uniqid(), -8)),
        'surname' => 'Sur' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
    ], $attrs));
}

/* ─── Landing page ─────────────────────────────────────────────────── */

test('Reports landing page renders 200 for admin', function () {
    $this->actingAs(rep_user('super_admin'));

    Livewire::test(Reports::class)->assertOk();
});

test('Reports landing page returns 403 for users without view_any_report', function () {
    // Build a role that explicitly lacks the report view permission —
    // syncPermissions([]) on the user only strips direct grants, not
    // role-derived ones, so we create a fresh "no_perms" role.
    rep_seedRoles();
    $denied = Role::firstOrCreate(['name' => 'no_perms_role', 'guard_name' => 'web']);
    $denied->syncPermissions([]);

    $u = User::factory()->create([
        'email' => 'no-perms+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->syncRoles([$denied]);
    $this->actingAs($u);

    expect(Reports::canAccess())->toBeFalse();
    expect(Reports::shouldRegisterNavigation())->toBeFalse();
});

test('All 5 report pages render 200 for admin', function () {
    $this->actingAs(rep_user('super_admin'));

    Livewire::test(DocumentsByBatchReport::class)->assertOk();
    Livewire::test(DocumentsByCreatorReport::class)->assertOk();
    Livewire::test(DocumentsBySeriesReport::class)->assertOk();
    Livewire::test(PendingDisinfestationReport::class)->assertOk();
    Livewire::test(DisinfestationCycleReport::class)->assertOk();
    Livewire::test(RasNraReconciliationReport::class)->assertOk();
    Livewire::test(BoxMovementHistoryReport::class)->assertOk();
});

test('viewer can access reports landing while editor sees the page too', function () {
    $this->actingAs(rep_user('viewer'));
    expect(Reports::canAccess())->toBeTrue();
    Livewire::test(Reports::class)->assertOk();

    $this->actingAs(rep_user('editor'));
    expect(Reports::canAccess())->toBeTrue();
    Livewire::test(Reports::class)->assertOk();
});

/* ─── DocumentsByBatchReport ───────────────────────────────────────── */

test('DocumentsByBatch returns correct grouping for a seeded dataset', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    $bA = rep_batch($repo->id);
    $bB = rep_batch($repo->id);

    rep_doc($repo->id, $series->id, ['batch_id' => $bA->id]);
    rep_doc($repo->id, $series->id, ['batch_id' => $bA->id]);
    rep_doc($repo->id, $series->id, ['batch_id' => $bA->id]);
    rep_doc($repo->id, $series->id, ['batch_id' => $bB->id]);

    $page = new DocumentsByBatchReport;
    $method = new ReflectionMethod($page, 'collectRows');
    $method->setAccessible(true);
    /** @var array<int, array<int, scalar|null>> $rows */
    $rows = $method->invoke($page);

    $byNumber = [];
    foreach ($rows as $row) {
        $byNumber[(string) $row[0]] = $row[3]; // batch_number => count
    }

    expect($byNumber[(string) $bA->batch_number])->toBe(3)
        ->and($byNumber[(string) $bB->batch_number])->toBe(1);
});

/* ─── DocumentsByCreatorReport ─────────────────────────────────────── */

test('DocumentsByCreator counts a Document attached to 2 authorities under BOTH', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    $doc = rep_doc($repo->id, $series->id);

    $a1 = rep_authority(['surname' => 'Abela', 'identifier' => 'R901-' . uniqid()]);
    $a2 = rep_authority(['surname' => 'Borg', 'identifier' => 'R902-' . uniqid()]);

    $doc->authorities()->attach([$a1->id, $a2->id]);

    $page = new DocumentsByCreatorReport;
    $method = new ReflectionMethod($page, 'collectRows');
    $method->setAccessible(true);
    /** @var array<int, array<int, scalar|null>> $rows */
    $rows = $method->invoke($page);

    $countsBySurname = [];
    foreach ($rows as $row) {
        $countsBySurname[(string) $row[1]] = (int) $row[3];
    }

    expect($countsBySurname['Abela'])->toBe(1)
        ->and($countsBySurname['Borg'])->toBe(1);

    // Total document mentions = 2 (same doc counted under 2 authorities).
    expect(array_sum($countsBySurname))->toBe(2);
});

/* ─── DocumentsBySeriesReport ──────────────────────────────────────── */

test('DocumentsBySeries returns counts correctly per series code', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $sX = Series::create(['code' => 'SX_' . substr(uniqid(), -4), 'title' => 'SX', 'is_active' => true]);
    $sY = Series::create(['code' => 'SY_' . substr(uniqid(), -4), 'title' => 'SY', 'is_active' => true]);

    rep_doc($repo->id, $sX->id);
    rep_doc($repo->id, $sX->id);
    rep_doc($repo->id, $sY->id);

    $page = new DocumentsBySeriesReport;
    $method = new ReflectionMethod($page, 'collectRows');
    $method->setAccessible(true);
    $rows = $method->invoke($page);

    $byCode = [];
    foreach ($rows as $row) {
        $byCode[(string) $row[0]] = (int) $row[2];
    }

    expect($byCode[$sX->code])->toBe(2)
        ->and($byCode[$sY->code])->toBe(1);
});

/* ─── PendingDisinfestationReport ──────────────────────────────────── */

test('PendingDisinfestation filters out PERM_OUT box documents', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    $batch = rep_batch($repo->id);

    $pending = rep_doc($repo->id, $series->id, ['disinfestation_date' => null, 'current_box_id' => null]);

    $permOutBox = rep_box($batch->id);
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('id', $permOutBox->id)
        ->update(['barcode_status' => 'PERM_OUT', 'disinfestation_date' => now()]);

    $permOutDoc = rep_doc($repo->id, $series->id, [
        'disinfestation_date' => null,
        'current_box_id' => $permOutBox->id,
    ]);

    $page = new PendingDisinfestationReport;
    $method = new ReflectionMethod($page, 'reportQuery');
    $method->setAccessible(true);
    /** @var Builder $q */
    $q = $method->invoke($page);

    $ids = $q->pluck('documents.id')->all();
    expect($ids)->toContain($pending->id);
    expect($ids)->not->toContain($permOutDoc->id);
});

test('PendingDisinfestation filters out rows with disinfestation_date set', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();

    $done = rep_doc($repo->id, $series->id, ['disinfestation_date' => '2025-01-15']);
    $pending = rep_doc($repo->id, $series->id, ['disinfestation_date' => null]);

    $page = new PendingDisinfestationReport;
    $method = new ReflectionMethod($page, 'reportQuery');
    $method->setAccessible(true);
    /** @var Builder $q */
    $q = $method->invoke($page);

    $ids = $q->pluck('documents.id')->all();
    expect($ids)->toContain($pending->id)
        ->and($ids)->not->toContain($done->id);
});

/* ─── BoxMovementHistoryReport ─────────────────────────────────────── */

test('BoxMovementHistory date-range filter narrows by movement_date', function () {
    $admin = rep_user('super_admin');
    $this->actingAs($admin);

    $repo = rep_repo();
    $series = rep_series();
    $batch = rep_batch($repo->id);
    $boxA = rep_box($batch->id);
    $boxB = rep_box($batch->id);
    $doc = rep_doc($repo->id, $series->id, ['current_box_id' => $boxA->id]);

    BoxMovement::create([
        'document_id' => $doc->id,
        'from_box_id' => $boxA->id,
        'to_box_id' => $boxB->id,
        'movement_date' => '2024-01-01 10:00:00',
        'reason' => 'old',
        'user_id' => $admin->id,
    ]);

    BoxMovement::create([
        'document_id' => $doc->id,
        'from_box_id' => $boxA->id,
        'to_box_id' => $boxB->id,
        'movement_date' => now()->subDay()->format('Y-m-d H:i:s'),
        'reason' => 'recent',
        'user_id' => $admin->id,
    ]);

    Livewire::test(BoxMovementHistoryReport::class)
        ->set('tableFilters.date_range.from', now()->subDays(7)->toDateString())
        ->assertCanSeeTableRecords(
            BoxMovement::query()->where('reason', 'recent')->get()
        )
        ->assertCanNotSeeTableRecords(
            BoxMovement::query()->where('reason', 'old')->get()
        );
});

/* ─── Multi-tenant scoping ─────────────────────────────────────────── */

test('reports respect RepositoryScope for editor users', function () {
    $repoA = rep_repo('A');
    $repoB = rep_repo('B');
    $series = rep_series();

    for ($i = 0; $i < 2; $i++) {
        rep_doc($repoA->id, $series->id);
    }
    for ($i = 0; $i < 5; $i++) {
        rep_doc($repoB->id, $series->id);
    }

    $editor = rep_user('editor');
    $editor->repositories()->attach($repoA->id);
    $editor->default_repository_id = $repoA->id;
    $editor->save();

    $this->actingAs($editor);

    $page = new DocumentsBySeriesReport;
    $method = new ReflectionMethod($page, 'collectRows');
    $method->setAccessible(true);
    $rows = $method->invoke($page);

    $total = 0;
    foreach ($rows as $row) {
        $total += (int) $row[2];
    }

    expect($total)->toBe(2);
});

/* ─── CSV export ───────────────────────────────────────────────────── */

test('CSV export streams correct Content-Type + filename pattern', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    rep_doc($repo->id, $series->id);

    $page = new DocumentsBySeriesReport;
    /** @var StreamedResponse $resp */
    $resp = $page->exportCsv();

    expect($resp->headers->get('Content-Type'))->toContain('text/csv');
    expect($resp->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($resp->headers->get('Content-Disposition'))->toContain('.csv')
        ->and($resp->headers->get('Content-Disposition'))->toContain('documents_by_series_');

    ob_start();
    $resp->sendContent();
    $body = ob_get_clean();

    $body = ltrim((string) $body, "\xEF\xBB\xBF");
    $firstLine = strtok($body, "\n");
    expect($firstLine)
        ->toContain('Code')
        ->toContain('Title')
        ->toContain('# Documents');
});

test('CSV export from PendingDisinfestation streams data rows', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    rep_doc($repo->id, $series->id, ['disinfestation_date' => null, 'identifier' => 'PEND-CSV-1']);
    rep_doc($repo->id, $series->id, ['disinfestation_date' => null, 'identifier' => 'PEND-CSV-2']);

    $page = new PendingDisinfestationReport;
    $resp = $page->exportCsv();

    ob_start();
    $resp->sendContent();
    $body = ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");

    expect($body)->toContain('PEND-CSV-1')
        ->and($body)->toContain('PEND-CSV-2');
});

/* ─── PDF export ───────────────────────────────────────────────────── */

test('PDF export returns Content-Type: application/pdf with non-empty body', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    rep_doc($repo->id, $series->id);

    $page = new DocumentsBySeriesReport;
    /** @var Response $resp */
    $resp = $page->exportPdf();

    expect($resp->headers->get('Content-Type'))->toBe('application/pdf');
    expect(strlen((string) $resp->getContent()))->toBeGreaterThan(1000);
    expect(substr((string) $resp->getContent(), 0, 5))->toBe('%PDF-');
});

test('PDF export sets attachment Content-Disposition with date-stamped filename', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    rep_doc($repo->id, $series->id);

    $page = new DocumentsByBatchReport;
    /** @var Response $resp */
    $resp = $page->exportPdf();

    $disposition = (string) $resp->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('.pdf')
        ->and($disposition)->toContain('documents_by_batch_')
        ->and((bool) preg_match('/documents_by_batch_\d{8}_\d{6}\.pdf/', $disposition))->toBeTrue();
});

/* ─── viewer 403 / admin 200 ───────────────────────────────────────── */

test('user without view_any_report gets a 403 on CSV export, admin gets a 200', function () {
    rep_seedRoles();
    $denied = Role::firstOrCreate(['name' => 'no_perms_role_csv', 'guard_name' => 'web']);
    $denied->syncPermissions([]);

    $u = User::factory()->create([
        'email' => 'csv-denied+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->syncRoles([$denied]);
    $this->actingAs($u);

    $page = new DocumentsBySeriesReport;
    expect(fn () => $page->exportCsv())
        ->toThrow(HttpException::class);

    $this->actingAs(rep_user('super_admin'));
    $resp = $page->exportCsv();
    expect($resp)->toBeInstanceOf(StreamedResponse::class);
});

/* ─── Caching ──────────────────────────────────────────────────────── */

test('landing page caches counts for 60 seconds', function () {
    $this->actingAs(rep_user('super_admin'));

    $repo = rep_repo();
    $series = rep_series();
    rep_doc($repo->id, $series->id);

    Cache::flush();

    $page = new Reports;
    $cards1 = $page->cards();
    // 6 canned reports + NAF Queries Q1 (cycle) + Q3 (RAS↔NRA reconciliation).
    expect($cards1)->toHaveCount(8);

    // Second call hits the cache — verify by checking the cache key.
    $uid = auth()->id();
    expect(Cache::has("reports:landing:counts:u={$uid}"))->toBeTrue();

    $cards2 = $page->cards();
    expect($cards2)->toEqual($cards1);
});
