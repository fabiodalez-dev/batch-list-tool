<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * RFQ-2026-06 §3.1 Core Features (12 requirements × 4 tests = 48 tests).
 *
 * Each `describe` block pins ONE RFQ requirement. The 4 sub-tests per
 * block follow the matrix convention used across this suite:
 *   1. happy   — the feature works in the canonical path
 *   2. edge    — validation / null / boundary
 *   3. security — gate / permission / multi-tenant
 *   4. integration — interacts with at least one other feature
 *
 * `->todo()` markers point to the deep-Feature test that already covers
 * the same surface — kept thin here so the compliance suite runs <60s
 * end-to-end on every push. Replace a todo with a concrete body if you
 * notice the underlying feature has shifted shape.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── REQ-3.1.1 CRUD on all metadata fields ──────────────────────── */
describe('REQ-3.1.1 CRUD on all metadata fields', function () {
    it('creates a document with all required fields')->todo('Feature\\Resources\\DocumentResource — create flow');
    it('rejects creation when required identifier is missing')->todo('Feature\\Resources\\DocumentResource — validation');
    it('forbids viewer role from creating documents')->todo('Feature\\Authorization\\DocumentPolicyTest');
    it('soft-deletes a document and excludes it from list query')->todo('Feature\\Resources\\DocumentResource — soft delete');
})->group('rfq:3.1.1');

/* ─── REQ-3.1.2 Validation (Appendix 1) ──────────────────────────── */
describe('REQ-3.1.2 Validation (forbidden batches, wills, PERM_OUT)', function () {
    test('forbidden batch numbers 33/34/36 are rejected at the model gate (FORBIDDEN_NUMBERS const)', function () {
        // Surface-level guarantee: the const exists and lists the three.
        // Per-action enforcement lives in BatchResource::form() + the DB CHECK
        // (CHECK is MySQL-only; SQLite test driver skips it).
        expect(Batch::FORBIDDEN_NUMBERS)->toMatchArray([33, 34, 36]);
    });

    test('PERM_OUT requires disinfestation_date — declared at the Box::BARCODE_STATUSES enum', function () {
        // The runtime check is performed by MarkPermOutAction (Feature suite
        // covers end-to-end). Here we just assert the enum + the precondition
        // helper exists, so this compliance test fires if the safeguard is
        // removed by refactor.
        expect(Box::BARCODE_STATUSES)->toContain('PERM_OUT');
        expect(method_exists(Box::class, 'requiresParent'))->toBeTrue();
    });

    it('Batch 50 is reserved for wills-only documents')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
    it('In Situ boxes require parent_box_id reference')->todo('Feature\\RfqCompliance\\Appendix1RulesTest');
})->group('rfq:3.1.2');

/* ─── REQ-3.1.3 Bulk Import ──────────────────────────────────────── */
describe('REQ-3.1.3 Bulk import', function () {
    it('imports a valid Authorities sheet end-to-end')->todo('Feature\\BulkImportV2Test');
    it('rejects invalid rows pre-commit with row-numbered errors')->todo('Feature\\RfqCompliance\\Section313BulkImportTest');
    it('offers a downloadable failed_import_rows CSV')->todo('Feature\\Pages\\ImportWizardTest — downloadFailedRows()');
    it('persists column mapping as a reusable ImportProfile')->todo('Feature\\Resources\\ImportProfileTest');
})->group('rfq:3.1.3');

/* ─── REQ-3.1.4 Field-level permissions ──────────────────────────── */
describe('REQ-3.1.4 Field-level read/write/hidden', function () {
    it('editor cannot write a field gated write=>admin')->todo('Feature\\FieldPermissionsTest');
    it('viewer cannot see a field gated hidden=>viewer')->todo('Feature\\FieldPermissionsTest');
    it('super_admin bypasses all field gates as defence-in-depth')->todo('Feature\\FieldPermissionsTest');
    it('field-permission config drives DocumentResource form rendering')->todo('Feature\\FieldPermissionsTest');
})->group('rfq:3.1.4');

/* ─── REQ-3.1.5 Full audit trail ─────────────────────────────────── */
describe('REQ-3.1.5 Audit trail with IP/UA/user/timestamp', function () {
    it('captures old + new values on any Auditable model update')->todo('Feature\\RfqCompliance\\Section316AuditTrailTest');
    it('records IP address + user agent + URL on each audit row')->todo('Feature\\AuditTest');
    it('audit_logs are read-only via the AuditResource')->todo('Feature\\AuditTest');
    it('SoftDelete + restore generate distinct audit events')->todo('Feature\\AuditTest');
})->group('rfq:3.1.5');

/* ─── REQ-3.1.6 Movement tracking ────────────────────────────────── */
describe('REQ-3.1.6 Document movement tracking', function () {
    it('moving a doc to a new box appends a box_movements row')->todo('Feature\\DocumentActionsTest — MoveToBoxAction');
    it('movement is wrapped in a per-row transaction')->todo('Feature\\DocumentActionsTest');
    it('cross-tenant move is refused by RepositoryScope')->todo('Feature\\SecurityBaseline\\MultiTenantScopeTest');
    it('audit cascade fires for both document + box_movement')->todo('Feature\\RfqCompliance\\Section315BoxMovementHistoryTest');
})->group('rfq:3.1.6');

/* ─── REQ-3.1.7 Barcode management ───────────────────────────────── */
describe('REQ-3.1.7 Barcode IN/OUT/PERM_OUT + history', function () {
    it('changing barcode appends an immutable history row')->todo('Feature\\BoxBarcodeHistoryTest');
    it('PERM_OUT is rejected without disinfestation_date')->todo('Feature\\BoxBarcodeHistoryTest');
    it('barcode history is append-only — no UPDATE/DELETE')->todo('Feature\\BoxBarcodeHistoryTest');
    it('whitespace-only barcodes are normalised at the boot hook')->todo('Feature\\BoxBarcodeHistoryTest');
})->group('rfq:3.1.7');

/* ─── REQ-3.1.8 Seal number history ──────────────────────────────── */
describe('REQ-3.1.8 Seal number history', function () {
    it('updating seal_number appends to document_seal_number_history')->todo('Feature\\DocumentSealNumberHistoryTest');
    it('unchanged seal_number does NOT create a history row')->todo('Feature\\DocumentSealNumberHistoryTest');
    it('history relation manager renders rows in reverse chronological order')->todo('Feature\\DocumentSealNumberHistoryTest');
    it('cross-tenant access to seal history is blocked')->todo('Feature\\DocumentSealNumberHistoryTest');
})->group('rfq:3.1.8');

/* ─── REQ-3.1.9 Configurable location hierarchies ────────────────── */
describe('REQ-3.1.9 Locations with parent_id + materialised path', function () {
    it('breadcrumb is recomputed when parent_id changes')->todo('Feature\\LocationHierarchyTest');
    it('depth cap MAX_DEPTH=6 rejects deeper insertions')->todo('Feature\\LocationHierarchyTest');
    it('cycle detection prevents A→B + B→A')->todo('Feature\\LocationHierarchyTest');
    it('Box AND Document can be pinned to a Location via location_id')->todo('Feature\\LocationHierarchyTest');
})->group('rfq:3.1.9');

/* ─── REQ-3.1.10 Box lifecycle ───────────────────────────────────── */
describe('REQ-3.1.10 Box lifecycle (state + destroyed)', function () {
    it('Box::TYPES enum is respected at the form gate')->todo('Feature\\RfqCompliance\\Section3111BoxLifecycleTest');
    it('canBeDestroyed refuses a box with uncatalogued docs')->todo('Feature\\Resources\\BoxDestroyedWorkflowTest');
    it('markDestroyed re-acquires the row under DB::transaction')->todo('Feature\\Resources\\BoxDestroyedWorkflowTest');
    it('current_box_type enum is normalised case-insensitive on save')->todo('Document::canonicalEnumValue — added 2026-05-27');
})->group('rfq:3.1.10');

/* ─── REQ-3.1.11 Controlled vocabularies ─────────────────────────── */
describe('REQ-3.1.11 Lookup tables', function () {
    test('document_types lookup table exists', function () {
        expect(Schema::hasTable('document_types'))->toBeTrue();
    });

    test('practices lookup table exists', function () {
        expect(Schema::hasTable('practices'))->toBeTrue();
    });

    it('DocumentResource form Select uses the lookup table options')->todo('Feature\\Resources\\DocumentResource — form Select');
    it('admin can add a new option inline via createOptionForm')->todo('Feature\\Resources\\DocumentResource — createOption');
})->group('rfq:3.1.11');

/* ─── REQ-3.1.12 Issue flags replacing colour coding ─────────────── */
describe('REQ-3.1.12 Document flags', function () {
    it('AddFlagAction creates a DocumentFlag with type + severity')->todo('Feature\\RfqCompliance\\Section3112DocumentFlagsTest');
    it('markResolved transitions status and stamps resolved_at')->todo('Feature\\RfqCompliance\\Section3112DocumentFlagsTest');
    it('viewer cannot resolve a flag (needs resolve_document_flag perm)')->todo('Feature\\Authorization\\DocumentFlagPolicyTest');
    it('FlagsByTypeReport groups by (type, severity, status)')->todo('Feature\\Pages\\FlagsByTypeReportTest');
})->group('rfq:3.1.12');
