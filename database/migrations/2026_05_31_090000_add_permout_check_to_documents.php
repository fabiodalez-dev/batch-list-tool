<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 (review finding) — DB-level A1.2 on `documents.barcode_status`.
 *
 * Mirrors the box-level CHECK added in
 * 2026_05_25_170005_create_boxes_table (chk_boxes_permout_requires_disinfestation):
 * a document cannot be PERM_OUT without a disinfestation_date. This is a second
 * line of defence on MariaDB/MySQL behind the model `saving` guard in
 * Document::booted(); SQLite (test suite) cannot retro-fit a CHECK, so the PHP
 * guard remains the cross-driver enforcement there.
 *
 * Guarded by driver (raw DDL runs only on mysql/mariadb) and made idempotent so
 * a re-run does not fail on the already-present constraint.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'chk_documents_permout_requires_disinfestation';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasColumn('documents', 'barcode_status')
            || ! Schema::hasColumn('documents', 'disinfestation_date')) {
            return;
        }

        // Drop first (idempotent re-run safety), then add.
        $this->dropConstraintIfExists();
        DB::statement('ALTER TABLE documents ADD CONSTRAINT ' . self::CONSTRAINT . " CHECK (barcode_status <> 'PERM_OUT' OR disinfestation_date IS NOT NULL)");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropConstraintIfExists();
    }

    /**
     * Cross-engine CHECK-constraint drop.
     *
     * The `mysql` Laravel driver covers BOTH MariaDB and MySQL 8/9, but their
     * DROP syntax diverges:
     *   - MariaDB supports `ALTER TABLE … DROP CONSTRAINT IF EXISTS <name>`.
     *   - MySQL 8/9 has NO `IF EXISTS` form for a CHECK; it needs
     *     `ALTER TABLE … DROP CHECK <name>`, which fatals if the constraint
     *     is absent — so we probe information_schema first.
     */
    private function dropConstraintIfExists(): void
    {
        $isMariaDb = str_contains(
            (string) (DB::selectOne('select version() as v')->v ?? ''),
            'MariaDB'
        );

        if ($isMariaDb) {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT);

            return;
        }

        // MySQL 8/9 — DROP CHECK has no IF EXISTS; guard on information_schema
        // so a missing constraint (e.g. fresh DB / first run) does not fatal.
        $exists = DB::selectOne(
            'SELECT 1 AS hit FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            ['documents', self::CONSTRAINT]
        );

        if ($exists !== null) {
            DB::statement('ALTER TABLE documents DROP CHECK ' . self::CONSTRAINT);
        }
    }
};
