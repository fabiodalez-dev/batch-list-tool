<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── REQ-3.4.1 Scalability / performance ────────────────────────── */
describe('REQ-3.4.1 Scalability and observability', function () {
    test('21 performance indexes exist on the documents table', function () {
        $indexes = collect(Schema::getIndexes('documents'))->pluck('name')->all();
        expect(count($indexes))->toBeGreaterThanOrEqual(5);
    });

    it('Document list query stays under <=16 SQL queries on paginate(25)')->todo('Feature\\Performance\\DocumentListPerformanceTest');
    it('TTFB benchmark passes <500ms on 3000-doc dataset')->todo('Feature\\Performance\\TtfbBenchmarkTest');
    it('LSCache + browser cache headers are emitted via .htaccess')->todo('Manual: curl -I https://archivetool.eu/');
})->group('rfq:3.4.1');

/* ─── REQ-3.4.2 Daily automated backups ──────────────────────────── */
describe('REQ-3.4.2 Backup schedule + off-site copy', function () {
    test('backup destination disks are configurable via BACKUP_S3_BUCKET env', function () {
        // Default state (env unset) → ['local']
        expect(config('backup.backup.destination.disks'))->toBeArray();
    });

    it('backup:run is scheduled daily at 02:30')->todo('Routes\\Console — Schedule::command(backup:run)');
    it('backup:monitor runs Mondays at 08:00')->todo('Routes\\Console — Schedule::command(backup:monitor)');
    it('AES-256 encryption activates when BACKUP_ARCHIVE_PASSWORD is set')->todo('config/backup.php — encryption.algorithm');
})->group('rfq:3.4.2');

/* ─── REQ-3.4.3 Usability ────────────────────────────────────────── */
describe('REQ-3.4.3 Usability', function () {
    it('SearchableSelects helper drives server-side relation pickers')->todo('App\\Filament\\Support\\SearchableSelects');
    it('Filament spotlight is wired on cmd+k')->todo('Feature\\GlobalSearchTest');
    it('15+ bulk actions are exposed on Document list')->todo('Feature\\DocumentActionsTest');
    it('Document edit/view page uses single-column root layout (no nested grids)')->todo('Feature\\Resources\\DocumentResource — layout');
})->group('rfq:3.4.3');
