<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Compliance\Helpers;

/*
 * RFQ-2026-06 Appendix 2 — Metadata Definitions (18 requirements × 4 tests = 72).
 * Same matrix convention: happy / edge / security / integration.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── APP2-i RAS Box ─────────────────────────────────────────────── */
describe('APP2-i RAS Box', function () {
    it('batches table accepts numbers 1-32 (Main Collection range)')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
    it('batch_number is unique per repository')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
    it('box_number is unique within a batch')->todo('Feature\\Resources\\BoxResourceTest');
    it('box seal_number history captures every change')->todo('Feature\\Boxes\\BoxSealHistoryTest');
})->group('rfq:app2-i');

/* ─── APP2-ii In Situ Box ────────────────────────────────────────── */
describe('APP2-ii In Situ Box', function () {
    it('IN_SITU box_type requires parent_box_id reference')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
    it('legacy MAV / STVC types reject new creation (is_legacy=true mandatory)')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
    test('Box.TYPES enum includes RAS, IN_SITU, NRA, MAV, STVC', function () {
        expect(Box::TYPES)->toEqualCanonicalizing(['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC'])
            ->and(Box::LEGACY_TYPES)->toEqualCanonicalizing(['MAV', 'STVC']);
    });
    it('Mounted / Not in Box state is derivable via current_box_id IS NULL')->todo('Manual / Feature\\Resources\\DocumentResource — display');
})->group('rfq:app2-ii');

/* ─── APP2-iii Box History ───────────────────────────────────────── */
describe('APP2-iii Box history', function () {
    it('box_movements row recorded on every box change')->todo('Feature\\RfqCompliance\\Section315BoxMovementHistoryTest');
    it('legacy POC ras_batch_* + in_situ_box_* columns preserved for migration parity')->todo('Feature\\BulkImportV2Test');
    it('3-round disinfestation dates are queryable separately')->todo('Feature\\PendingDisinfestationReportTest');
    it('canonical disinfestation_date drives the PendingDisinfestation report')->todo('Feature\\PendingDisinfestationReportTest');
})->group('rfq:app2-iii');

/* ─── APP2-iv Barcodes ───────────────────────────────────────────── */
describe('APP2-iv Barcodes', function () {
    it('append-only box_barcode_history table')->todo('Feature\\BoxBarcodeHistoryTest');
    it('composite index (box_id, changed_at) speeds lookup')->todo('Manual: SHOW INDEXES');
    it('boot hook captures barcode change automatically')->todo('Feature\\BoxBarcodeHistoryTest');
    it('whitespace-only barcodes are dropped (not stored as "  ")')->todo('Feature\\BoxBarcodeHistoryTest');
})->group('rfq:app2-iv');

/* ─── APP2-v NRA Location ────────────────────────────────────────── */
describe('APP2-v NRA Location', function () {
    it('Location.TYPES includes Archive, Cataloguing, Museum')->todo('Feature\\LocationHierarchyTest');
    test('Box AND Document carry a location_id FK', function () {
        expect(Schema::hasColumn('documents', 'location_id'))->toBeTrue()
            ->and(Schema::hasColumn('boxes', 'location_id'))->toBeTrue();
    });
    it('breadcrumb accessor renders full path')->todo('Feature\\LocationHierarchyTest');
    it('cycle detection refuses parent-of-self insertions')->todo('Feature\\LocationHierarchyTest');
})->group('rfq:app2-v');

/* ─── APP2-vi Museum Location ────────────────────────────────────── */
describe('APP2-vi Museum location wizard', function () {
    it('SetMuseumLocationAction dropdown is filtered to type IN (museum, showcase)')->todo('Feature\\Actions\\SetMuseumLocationActionTest');
    it('museum_reference is required in the same modal')->todo('Feature\\Actions\\SetMuseumLocationActionTest');
    it('optional notes are timestamp-prefixed + APPENDED never overwriting')->todo('Feature\\Actions\\SetMuseumLocationActionTest');
    it('bulk variant deselects records after success')->todo('Feature\\Actions\\SetMuseumLocationActionTest');
})->group('rfq:app2-vi');

/* ─── APP2-vii Box Destroyed ─────────────────────────────────────── */
describe('APP2-vii Box destroyed workflow', function () {
    test('canBeDestroyed refuses when any doc lacks catalogue_identifier', function () {
        $repo = Helpers::repo();
        $batch = Helpers::batch($repo->id);
        $box = Helpers::box($batch->id);
        $series = Helpers::series();
        Helpers::doc($repo->id, $series->id, $box->id, ['catalogue_identifier' => null]);

        $check = $box->canBeDestroyed();
        expect($check['ok'])->toBeFalse();
    });

    it('soft-deleted uncatalogued doc still blocks destruction')->todo('Feature\\Resources\\BoxDestroyedWorkflowTest');
    it('markDestroyed under DB::transaction + lockForUpdate is race-safe')->todo('Feature\\Resources\\BoxDestroyedWorkflowTest');
    it('DestroyBoxAction is gated on the delete_box Shield permission')->todo('Feature\\Resources\\BoxDestroyedWorkflowTest');
})->group('rfq:app2-vii');

/* ─── APP2-viii Catalogue Identifier ─────────────────────────────── */
describe('APP2-viii Catalogue identifier', function () {
    test('catalogue_identifier UNIQUE index rejects duplicate non-null values', function () {
        $repo = Helpers::repo();
        $series = Helpers::series();
        Helpers::doc($repo->id, $series->id, null, ['catalogue_identifier' => 'CAT-DUP']);

        expect(fn () => Helpers::doc($repo->id, $series->id, null, ['catalogue_identifier' => 'CAT-DUP']))
            ->toThrow(QueryException::class);
    });

    it('Uncatalogued filter on Document list returns whereNull catalogue_identifier')->todo('Feature\\Resources\\DocumentResource — uncatalogued TernaryFilter');
    it('nra:check-duplicate-catalogue-identifier preflight detects duplicates')->todo('Feature\\Commands\\CheckDuplicateCatalogueIdentifierTest');
    it('NULL catalogue_identifier rows are NOT reported as duplicates')->todo('Feature\\Commands\\CheckDuplicateCatalogueIdentifierTest');
})->group('rfq:app2-viii');

/* ─── APP2-ix Current Box Type ───────────────────────────────────── */
describe('APP2-ix Current box type enum', function () {
    test('CURRENT_BOX_TYPES enum is normalised case-insensitive on save', function () {
        $repo = Helpers::repo();
        $series = Helpers::series();
        $doc = Helpers::doc($repo->id, $series->id, null, ['current_box_type' => 'ras box']);
        $doc->refresh();
        expect($doc->current_box_type)->toBe('RAS Box');
    });

    test('invalid current_box_type throws DomainException at save', function () {
        $repo = Helpers::repo();
        $series = Helpers::series();
        expect(fn () => Helpers::doc($repo->id, $series->id, null, ['current_box_type' => 'Cardboard Box']))
            ->toThrow(DomainException::class);
    });
    it('form Select replaces free-text input')->todo('Feature\\Resources\\DocumentResource — Select');
    it('CHECK constraint enforces enum at DB level on MySQL')->todo('Migration 2026_05_27_170100_tighten_document_lookups');
})->group('rfq:app2-ix');

/* ─── APP2-x Disinfestation ──────────────────────────────────────── */
describe('APP2-x Disinfestation', function () {
    it('SendToDisinfestationAction stamps is_in_disinfestation=true')->todo('Feature\\DocumentActionsTest');
    it('MarkDisinfestedAction stamps disinfestation_date=now()')->todo('Feature\\DocumentActionsTest');
    it('Currently in disinfestation TernaryFilter shows only is_in_disinfestation=true')->todo('Feature\\Resources\\DocumentResource — TernaryFilter');
    it('MarkPermOutAction refuses doc without disinfestation_date')->todo('Feature\\DocumentActionsTest');
})->group('rfq:app2-x');

/* ─── APP2-xi Creator (multi-creator import) ─────────────────────── */
describe('APP2-xi Multi-creator import', function () {
    it('semicolon-delimited Identifier cell parses into multiple Authority rows')->todo('Feature\\BulkImportV2Test — splitSemicolonList');
    it('empty pieces (";;" or ";") are silently skipped')->todo('Feature\\BulkImportV2Test');
    test('SEMICOLON_DELIMITER constant is wired (not hard-coded)', function () {
        expect(DocumentImporter::SEMICOLON_DELIMITER)->toBe(';');
    });
    it('first parsed Authority is marked is_primary=true')->todo('Feature\\BulkImportV2Test');
})->group('rfq:app2-xi');

/* ─── APP2-xii Document metadata ─────────────────────────────────── */
describe('APP2-xii Document metadata fields', function () {
    it('9 canonical fields (identifier, creator, practice, volume_label, dates, deeds, document_type, series, notes) all editable')->todo('Feature\\Resources\\DocumentResource');
    it('DocumentIdentifierHistory captures every identifier change')->todo('Feature\\DocumentIdentifierHistoryTest');
    it('IdentifierHistoryRelationManager surfaces history on the View page')->todo('Feature\\Resources\\DocumentResource — relation managers');
    it('dates free-text + parsed year_range coexist (precise + raw)')->todo('Feature\\Resources\\DocumentResource — date parsing');
})->group('rfq:app2-xii');

/* ─── APP2-xiii Digitisation ─────────────────────────────────────── */
describe('APP2-xiii Digitised enum', function () {
    test('digitised enum normalises case-insensitive Vhmml -> VHMML', function () {
        $repo = Helpers::repo();
        $series = Helpers::series();
        $doc = Helpers::doc($repo->id, $series->id, null, ['digitised' => 'Vhmml']);
        $doc->refresh();
        expect($doc->digitised)->toBe('VHMML');
    });

    test('values outside {VHMML, NRA, none} throw DomainException', function () {
        $repo = Helpers::repo();
        $series = Helpers::series();
        expect(fn () => Helpers::doc($repo->id, $series->id, null, ['digitised' => 'flatbed-scan']))
            ->toThrow(DomainException::class);
    });
    it('CHECK constraint enforces enum at MySQL level')->todo('Migration 2026_05_27_170100_tighten_document_lookups');
    it('digitised column visible in DocumentResource list view (toggleable)')->todo('Feature\\Resources\\DocumentResource — list columns');
})->group('rfq:app2-xiii');

/* ─── APP2-xiv Torre ─────────────────────────────────────────────── */
describe('APP2-xiv Torre boolean', function () {
    it('Toggle in form persists boolean true/false')->todo('Feature\\Resources\\DocumentResource — torre Toggle');
    it('IconColumn renders torre in the list view')->todo('Feature\\Resources\\DocumentResource — list columns');
    it('TernaryFilter on torre filters the list')->todo('Feature\\Resources\\DocumentResource — TernaryFilter');
    it('torre is included in audit_logs old/new diff')->todo('Feature\\AuditTest');
})->group('rfq:app2-xiv');

/* ─── APP2-xv Object Reference Number ────────────────────────────── */
describe('APP2-xv Object reference number (fallback)', function () {
    test('displayIdentifier cascades catalogue_identifier → object_reference_number → identifier', function () {
        $doc = new Document([
            'identifier' => 'R-IDENT',
            'object_reference_number' => 'OBJ-REF',
            'catalogue_identifier' => 'CAT-1',
        ]);
        expect($doc->display_identifier)->toBe('CAT-1');
    });
    it('object_reference_number is searchable via omni-search')->todo('Feature\\DocumentOmniSearchTest');
    it('field has 500-char max length')->todo('Feature\\Resources\\DocumentResource — form maxLength');
    test('fallback fires only when catalogue_identifier IS NULL', function () {
        $noCat = new Document(['identifier' => 'R-IDENT', 'object_reference_number' => 'OBJ-REF']);
        expect($noCat->display_identifier)->toBe('OBJ-REF');

        $onlyIdent = new Document(['identifier' => 'R-IDENT']);
        expect($onlyIdent->display_identifier)->toBe('R-IDENT');
    });
})->group('rfq:app2-xv');

/* ─── APP2-xvi Tracking ──────────────────────────────────────────── */
describe('APP2-xvi Tracking field', function () {
    it('free-text tracking persists up to 500 chars')->todo('Feature\\Resources\\DocumentResource — form maxLength');
    it('tracking is searchable')->todo('Feature\\DocumentOmniSearchTest');
    it('tracking visible in DocumentResource list (toggleable)')->todo('Feature\\Resources\\DocumentResource — list columns');
    it('cross-tenant tracking lookup is scoped')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
})->group('rfq:app2-xvi');

/* ─── APP2-xvii Museum Reference ─────────────────────────────────── */
describe('APP2-xvii Museum reference', function () {
    it('FULLTEXT index covers museum_reference')->todo('Manual: SHOW INDEXES');
    it('dedicated filter on Document list')->todo('Feature\\Resources\\DocumentResource — filter');
    it('SetMuseumLocationAction stamps the field')->todo('Feature\\Actions\\SetMuseumLocationActionTest');
    it('field visible in list view')->todo('Feature\\Resources\\DocumentResource — list columns');
})->group('rfq:app2-xvii');

/* ─── APP2-xviii Flags by Type ───────────────────────────────────── */
describe('APP2-xviii Colour coding -> document flags', function () {
    test('15 type enums (incl. 6 colour-code mappings) + 3 severity + 4 status are exhaustively typed', function () {
        expect(DocumentFlag::TYPES)->toHaveCount(15)
            ->and(DocumentFlag::SEVERITIES)->toEqualCanonicalizing(['info', 'warning', 'critical'])
            ->and(DocumentFlag::STATUSES)->toEqualCanonicalizing(['open', 'acknowledged', 'resolved', 'dismissed']);
    });

    test('the 6 RFQ App.2-xviii colour codes are each mapped to a registered flag type', function () {
        expect(DocumentFlag::COLOUR_TYPES)->toHaveCount(6);
        foreach (DocumentFlag::COLOUR_TYPES as $colour => $type) {
            expect(in_array($type, DocumentFlag::TYPES, true))->toBeTrue("colour {$colour} -> {$type} missing from TYPES");
        }
    });
    it('Open flags TernaryFilter on Document list filters has_open_flags=true')->todo('Feature\\Resources\\DocumentResource — TernaryFilter');
    it('FlagsByTypeReport groups COUNT/SUM(CASE) by type+severity')->todo('Feature\\Pages\\FlagsByTypeReportTest');
    it('resolved flags do NOT show in the "Open flags" filter result')->todo('Feature\\Pages\\FlagsByTypeReportTest');
})->group('rfq:app2-xviii');
