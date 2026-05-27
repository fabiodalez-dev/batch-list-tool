<?php

declare(strict_types=1);

use App\Models\ReportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── REQ-3.2.1 Search across records ────────────────────────────── */
describe('REQ-3.2.1 Omni-search', function () {
    it('searches across identifier, document_type, notes, deeds')->todo('Feature\\DocumentOmniSearchTest');
    it('joins authorities + series + batch + box + location + flags + accession')->todo('Feature\\DocumentOmniSearchTest');
    it('MySQL FULLTEXT indexes power the notes/deeds/museum_reference matches')->todo('Feature\\DocumentOmniSearchTest');
    it('cross-tenant search results are scoped by RepositoryScope')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
})->group('rfq:3.2.1');

/* ─── REQ-3.2.2 Report builder ───────────────────────────────────── */
describe('REQ-3.2.2 Report builder with templates + exports', function () {
    test('ReportTemplate model exists and is multi-tenant scoped', function () {
        expect(ReportTemplate::class)->toBeString();
        expect(method_exists(ReportTemplate::class, 'scopeAccessibleBy'))->toBeTrue();
    });

    it('5 canned reports render under the Reports landing page')->todo('Feature\\ReportsTest');
    it('CSV export carries UTF-8 BOM + sanitised cells (formula injection)')->todo('Feature\\ReportsTest — sanitizeCsvCell');
    it('Save as template + restore via ?template=N round-trips state')->todo('Feature\\Resources\\ReportTemplateTest');
})->group('rfq:3.2.2');
