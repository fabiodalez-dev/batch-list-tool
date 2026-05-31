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
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasColumn('documents', 'barcode_status')
            || ! Schema::hasColumn('documents', 'disinfestation_date')) {
            return;
        }

        // Drop first (idempotent re-run safety), then add. MariaDB and MySQL 8
        // both support IF EXISTS on DROP CONSTRAINT.
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_documents_permout_requires_disinfestation');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_permout_requires_disinfestation CHECK (barcode_status <> 'PERM_OUT' OR disinfestation_date IS NOT NULL)");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS chk_documents_permout_requires_disinfestation');
    }
};
