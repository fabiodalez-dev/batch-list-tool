<?php

declare(strict_types=1);

use App\Exports\GenericReportExport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * RFQ §3.2 — Excel export support across reports.
 *
 * Three guarantees:
 *   1. exportXlsx() on a report page produces a non-empty xlsx stream
 *   2. xlsx headers match the page's getXlsxColumns() keys
 *   3. RepositoryScope still applies to xlsx exports (cross-tenant safety)
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function xls_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function xls_user(string $role = 'super_admin'): User
{
    xls_seedRoles();
    $u = User::factory()->create([
        'email' => 'xls-' . $role . '-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function xls_repo(string $prefix = 'XLS'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function xls_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'XLS_' . substr(uniqid(), -4)],
        ['title' => 'XLS series', 'is_active' => true],
    );
}

function xls_batch(int $repoId): Batch
{
    do {
        $n = random_int(2000, 8999);
    } while (in_array($n, [33, 34, 36], true) || Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function xls_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'XLS-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

test('exportXlsx on DocumentsByBatchReport produces a non-empty xlsx response', function () {
    $this->actingAs(xls_user());

    $repo = xls_repo();
    $series = xls_series();
    $batch = xls_batch($repo->id);
    xls_doc($repo->id, $series->id, ['batch_id' => $batch->id]);
    xls_doc($repo->id, $series->id, ['batch_id' => $batch->id]);

    $page = new DocumentsByBatchReport;
    $resp = $page->exportXlsx();

    // BinaryFileResponse sets Content-Disposition with the xlsx filename.
    $disposition = (string) $resp->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('.xlsx')
        ->and($disposition)->toContain('documents_by_batch_');

    // Status 200 + non-empty payload (xlsx zip header starts with PK).
    expect($resp->getStatusCode())->toBe(200);

    if ($resp instanceof BinaryFileResponse) {
        $contents = file_get_contents($resp->getFile()->getPathname());
        expect($contents)->not->toBeEmpty();
        expect(substr((string) $contents, 0, 2))->toBe('PK');
    } else {
        ob_start();
        $resp->sendContent();
        $body = (string) ob_get_clean();
        expect($body)->not->toBeEmpty();
        expect(substr($body, 0, 2))->toBe('PK');
    }
});

test('xlsx contains the expected headers from getXlsxColumns()', function () {
    $this->actingAs(xls_user());

    $repo = xls_repo();
    $series = xls_series();
    $batch = xls_batch($repo->id);
    xls_doc($repo->id, $series->id, ['batch_id' => $batch->id]);

    $page = new DocumentsByBatchReport;
    $columns = $page->getXlsxColumns();

    // The Excel exporter passes array_keys(columns) as headings — assert
    // the page declares exactly the columns the brief asks for.
    expect(array_keys($columns))->toEqual(['Batch #', 'Description', 'Type', '# Documents']);

    // Round-trip through GenericReportExport: build the exporter ourselves
    // and assert the in-memory headings + first row.
    $rowsMethod = new ReflectionMethod($page, 'collectRowsAsAssoc');
    $rows = $rowsMethod->invoke($page);

    $exporter = new GenericReportExport($rows, $columns, 'Documents by batch');

    expect($exporter->headings())->toEqual(['Batch #', 'Description', 'Type', '# Documents']);

    $mapped = $exporter->map($rows[0]);
    expect($mapped)->toHaveCount(4);
    expect((string) $mapped[0])->toBe((string) $batch->batch_number);
    expect((int) $mapped[3])->toBeGreaterThanOrEqual(1);
});

test('xlsx export honours RepositoryScope (cross-tenant safety)', function () {
    xls_seedRoles();

    $repoA = xls_repo('A');
    $repoB = xls_repo('B');
    $series = xls_series();

    for ($i = 0; $i < 2; $i++) {
        xls_doc($repoA->id, $series->id);
    }
    for ($i = 0; $i < 7; $i++) {
        xls_doc($repoB->id, $series->id);
    }

    $editor = User::factory()->create([
        'email' => 'xls-editor-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoA->id);
    $editor->default_repository_id = $repoA->id;
    $editor->save();

    $this->actingAs($editor);

    $page = new DocumentsBySeriesReport;
    $method = new ReflectionMethod($page, 'collectRowsAsAssoc');
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $method->invoke($page);

    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row['document_count'] ?? 0);
    }

    // Editor sees ONLY repoA → 2 documents, never the 7 from repoB.
    expect($total)->toBe(2);
});
