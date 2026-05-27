<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ-2026-06 — lock down three loose Document lookup fields.
 *
 *   APP2-viii  — `catalogue_identifier` must be a permanent UNIQUE id once
 *                assigned. Today it is a simple index → tighten it to a
 *                NULL-aware UNIQUE so multiple un-catalogued docs (NULL)
 *                remain legal but two catalogued docs cannot collide.
 *   APP2-ix    — `current_box_type` is free-text today. RFQ enumerates it:
 *                'RAS Box' | 'Big Brown Box' | 'Small Brown Box'
 *                (Big Brown counts as 2 boxes in the 250-per-cycle limit).
 *   APP2-xiii  — `digitised` is free-text today. RFQ enumerates the
 *                digitisation source: 'VHMML' | 'NRA' | 'none' | NULL.
 *
 * The DB-level CHECK constraint is only added on MySQL; on SQLite the
 * model-level `saving` guard in App\Models\Document covers it (SQLite would
 * need a table rebuild to add a CHECK after-the-fact and that's not worth
 * the operational risk for a test driver).
 *
 * The migration is idempotent against an existing DB that may already carry
 * the simple `documents_catalogue_identifier_index` — it probes
 * `information_schema.STATISTICS` (MySQL) / `sqlite_master` (SQLite) before
 * dropping anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // --- APP2-viii: catalogue_identifier UNIQUE (NULL-aware) ------------
        // Drop the existing simple index if present so we can re-add as UNIQUE.
        if ($this->indexExists('documents', 'documents_catalogue_identifier_index')) {
            try {
                Schema::table('documents', function (Blueprint $t): void {
                    $t->dropIndex('documents_catalogue_identifier_index');
                });
            } catch (QueryException $e) {
                // Tolerate "index does not exist" if probe missed it.
            }
        }

        // Skip re-creation if the unique index is already in place (re-run).
        if (! $this->indexExists('documents', 'documents_catalogue_identifier_unique')) {
            if ($driver === 'sqlite') {
                // SQLite: native partial-unique on NOT NULL — multiple NULLs stay legal.
                DB::statement(
                    'CREATE UNIQUE INDEX documents_catalogue_identifier_unique '
                    . 'ON documents (catalogue_identifier) WHERE catalogue_identifier IS NOT NULL'
                );
            } else {
                // MySQL / Postgres: a plain UNIQUE index already treats NULL as
                // distinct, so the semantics are equivalent without the WHERE
                // clause (which MySQL does not support on plain B-tree).
                Schema::table('documents', function (Blueprint $t): void {
                    $t->unique('catalogue_identifier', 'documents_catalogue_identifier_unique');
                });
            }
        }

        // --- APP2-xiii: digitised CHECK constraint --------------------------
        // --- APP2-ix:   current_box_type CHECK constraint -------------------
        // MySQL only — SQLite cannot ALTER TABLE ... ADD CONSTRAINT and the
        // model-level guard covers it for tests.
        if ($driver === 'mysql') {
            // Drop first in case of re-run, then add. ALTER … DROP CONSTRAINT
            // IF EXISTS is MySQL 8+ only; fall back to a try/catch otherwise.
            try {
                DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_digitised_chk');
            } catch (QueryException $e) {
                // Constraint did not exist — fine.
            }

            try {
                DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_current_box_type_chk');
            } catch (QueryException $e) {
                // Constraint did not exist — fine.
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
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            try {
                DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_current_box_type_chk');
            } catch (QueryException $e) {
                // already gone
            }

            try {
                DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_digitised_chk');
            } catch (QueryException $e) {
                // already gone
            }
        }

        if ($this->indexExists('documents', 'documents_catalogue_identifier_unique')) {
            try {
                Schema::table('documents', function (Blueprint $t): void {
                    $t->dropUnique('documents_catalogue_identifier_unique');
                });
            } catch (QueryException $e) {
                // tolerate
            }
        }

        // Re-add the original simple index so down() is a true inverse of up().
        if (! $this->indexExists('documents', 'documents_catalogue_identifier_index')) {
            try {
                Schema::table('documents', function (Blueprint $t): void {
                    $t->index('catalogue_identifier');
                });
            } catch (QueryException $e) {
                // tolerate
            }
        }
    }

    /**
     * Pure-SQL probe for an existing index by name — works on MySQL and
     * SQLite without depending on the optional DBAL schema manager.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$table, $indexName],
            );

            return $rows !== [];
        }

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ? LIMIT 1",
                [$table, $indexName],
            );

            return $rows !== [];
        }

        return false;
    }
};
