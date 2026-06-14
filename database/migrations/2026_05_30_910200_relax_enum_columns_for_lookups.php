<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ §3.1.11 (part 2 of 3) — relax the rigid DB-level enum/CHECK constraints
 * on the columns that are now governed by the editable lookup tables
 * (App\Models\Lookup\*) plus the model-level App\Support\Lookups guard.
 *
 * Principle: EXPAND, never restrict. The lookup tables + app validation become
 * the single source of truth for the allowed set; the DB column is widened to a
 * plain string so an operator adding a new active lookup value never has to
 * touch the schema. The Task-4 create-lookups migration already seeds every
 * historical value, so this loosening introduces no data risk.
 *
 * Forward-applicable: the staging MariaDB has already run the original create
 * migrations, so this is a NEW forward ALTER (editing the create migrations
 * would not re-run there). MySQL/MariaDB-guarded; SQLite is a no-op because the
 * test DB is rebuilt fresh and those columns are already plain strings there
 * (the enum/CHECK constraints were only ever applied on MySQL).
 *
 * Deliberately NOT touched (out of 3.1.11 scope):
 *   - chk_documents_custody_status   (custody is a separate concern)
 *   - the batches forbidden-numbers CHECK
 *   - batches.type ENUM               (no strict lookup guard added for it)
 *   - chk_boxes_permout_requires_disinfestation / chk_boxes_legacy_types
 *     (business rules, not value-set enums)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // SQLite: columns are already plain strings; nothing to relax.
        }

        // boxes.box_type ENUM(...) → VARCHAR(32). Original column: NOT NULL,
        // no default (create_boxes_table). Keep that nullability.
        if (Schema::hasColumn('boxes', 'box_type')) {
            DB::statement('ALTER TABLE boxes MODIFY COLUMN box_type VARCHAR(32) NOT NULL');
        }

        // boxes.barcode_status ENUM(...) DEFAULT 'IN' → VARCHAR(16) DEFAULT 'IN'.
        if (Schema::hasColumn('boxes', 'barcode_status')) {
            DB::statement("ALTER TABLE boxes MODIFY COLUMN barcode_status VARCHAR(16) NOT NULL DEFAULT 'IN'");
        }

        // document_flags.type ENUM(15) → VARCHAR(64) NOT NULL. Drops the
        // wave-1 15-value ENUM so the flag_types lookup governs the set.
        if (Schema::hasColumn('document_flags', 'type')) {
            DB::statement('ALTER TABLE document_flags MODIFY COLUMN type VARCHAR(64) NOT NULL');
        }

        // documents.digitised / current_box_type are already VARCHAR columns;
        // the rigidity lives in the named CHECK constraints added by
        // 2026_05_27_170100_tighten_document_lookups. Drop them (IF EXISTS via
        // try/catch for MySQL 5.7/MariaDB compatibility).
        $this->dropCheckIfExists('documents', 'documents_digitised_chk');
        $this->dropCheckIfExists('documents', 'documents_current_box_type_chk');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Restore the boxes ENUMs.
        if (Schema::hasColumn('boxes', 'box_type')) {
            DB::statement("ALTER TABLE boxes MODIFY COLUMN box_type ENUM('RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC') NOT NULL");
        }
        if (Schema::hasColumn('boxes', 'barcode_status')) {
            DB::statement("ALTER TABLE boxes MODIFY COLUMN barcode_status ENUM('IN', 'OUT', 'PERM_OUT') NOT NULL DEFAULT 'IN'");
        }

        // Restore the document_flags 15-value ENUM.
        if (Schema::hasColumn('document_flags', 'type')) {
            $types = [
                'needs_review', 'missing_data', 'duplicate_suspect', 'damaged',
                'restoration_needed', 'wrongly_catalogued', 'authority_mismatch',
                'barcode_issue', 'disinfestation_overdue', 'entry_issue',
                'location_check', 'not_disinfested_onsite', 'mould_treatment',
                'fragment_sorted', 'other',
            ];
            $values = collect($types)->map(fn (string $t) => "'{$t}'")->implode(', ');
            DB::statement("ALTER TABLE document_flags MODIFY COLUMN type ENUM({$values}) NOT NULL");
        }

        // Restore the documents CHECK constraints (mirrors the tighten migration).
        try {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_digitised_chk');
        } catch (QueryException $e) {
            // not present
        }

        try {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_current_box_type_chk');
        } catch (QueryException) {
            // not present
        }
        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_digitised_chk '
            . "CHECK (digitised IS NULL OR digitised IN ('VHMML', 'NRA', 'none'))"
        );
        DB::statement(
            'ALTER TABLE documents ADD CONSTRAINT documents_current_box_type_chk '
            . "CHECK (current_box_type IS NULL OR current_box_type IN ('RAS Box', 'Big Brown Box', 'Small Brown Box'))"
        );
    }

    /**
     * Drop a named CHECK constraint if present. MySQL 8+ / MariaDB 10.2+ both
     * support `ALTER TABLE ... DROP CONSTRAINT`; the try/catch tolerates the
     * "constraint does not exist" case (re-run) and older servers.
     */
    private function dropCheckIfExists(string $table, string $constraint): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        } catch (QueryException) {
            // Constraint absent (fresh DB never had it, or already dropped) — fine.
        }
    }
};
