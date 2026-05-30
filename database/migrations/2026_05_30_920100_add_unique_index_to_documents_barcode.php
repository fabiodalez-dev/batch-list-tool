<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review I1 (Fix 4) — enforce uniqueness of the per-document barcode value at
 * the database level.
 *
 * The per-document barcode (added in 2026_05_30_910250_add_document_barcode_and_history)
 * is an optional physical-label identifier. Two documents must never share the
 * same non-null barcode, but MANY documents may legitimately have NO barcode.
 *
 * A standard SQL UNIQUE index gives us exactly this on both target engines:
 * MariaDB and SQLite both treat NULLs as distinct, so multiple NULL barcodes
 * are allowed while any duplicate non-null value is rejected at INSERT/UPDATE
 * time with an integrity-constraint violation. No partial/filtered index is
 * required (and SQLite < 3.8.0 wouldn't support one anyway), keeping the
 * migration portable per the app's MariaDB-portable convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->unique('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropUnique(['barcode']);
        });
    }
};
