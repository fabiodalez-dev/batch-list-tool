<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client point #3 — import each RAS box's HISTORICAL barcodes (the legacy
 * "Barcode RAS 1/2/3/4" + "Status 1/2/3/4" columns, plus "Barcode (IN)") from
 * the document sheet into `box_barcode_history`.
 *
 * Two schema changes let those undated legacy rows coexist with the live
 * observer-written ones:
 *
 *   1. `changed_at` NOT NULL → NULLABLE. The client cannot date a historical
 *      barcode change, so a legacy-import row carries a NULL `changed_at`
 *      (mirrors the box-MOVEMENT timeline's undated legacy moves). The
 *      `useCurrent()` default and the (changed_at) index are kept, so an
 *      observer-written row still gets now() and the timeline query is unchanged.
 *
 *   2. `source` — 'recorded' (default, the live observer hook) vs 'legacy_import'
 *      (this importer). The importer delete-and-rebuilds ONLY its own
 *      'legacy_import' rows, never touching a 'recorded' operator change.
 *
 * Cross-driver: Laravel 13's native ->change() rebuilds the SQLite table (used
 * by the test suite) and issues a MODIFY on MySQL/MariaDB (dev + prod), so no
 * doctrine/dbal dependency and no raw driver-specific SQL is needed — the same
 * idiom as 2026_08_19_130000_add_legacy_dating_to_box_movements.php. The
 * existing (changed_at) and (box_id, changed_at) indexes survive a nullability
 * change untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_barcode_history', function (Blueprint $table): void {
            $table->timestamp('changed_at')->nullable()->useCurrent()->change();
        });

        Schema::table('box_barcode_history', function (Blueprint $table): void {
            $table->string('source', 20)->default('recorded')->after('changed_at');
            $table->index(['box_id', 'source'], 'box_barcode_history_box_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('box_barcode_history', function (Blueprint $table): void {
            $table->dropIndex('box_barcode_history_box_source_idx');
            $table->dropColumn('source');
        });

        // Restore NOT NULL. Any legacy rows carrying a NULL changed_at would
        // block this; acceptable for a reversal (dev only) — the up() path is
        // what ships.
        Schema::table('box_barcode_history', function (Blueprint $table): void {
            $table->timestamp('changed_at')->nullable(false)->useCurrent()->change();
        });
    }
};
