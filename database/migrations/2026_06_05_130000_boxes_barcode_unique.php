<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A10 (Wave A) — enforce boxes.barcode as globally unique at the DB level.
 *
 * The create_boxes_table migration already added ->unique() on barcode, which
 * produces an auto-named index (boxes_barcode_unique).  This migration
 * adds an explicitly SHORT-named index (boxes_barcode_uq) for MariaDB
 * compatibility (64-char limit on index names) and is idempotent: if either
 * the short-named index OR the auto-named index already exists, the migration
 * is a no-op.
 *
 * On a fresh install the create_boxes_table migration runs first and creates
 * boxes_barcode_unique, so this migration finds it and skips.  On any
 * installation where that index is absent for any reason, boxes_barcode_uq
 * is created.
 *
 * NOTE: barcode is still nullable at the column level so that legacy /
 * provisional rows (bulk import before the sticker is affixed) can have
 * barcode = NULL.  Multiple NULL barcodes are allowed (both MariaDB and
 * SQLite treat NULLs as distinct in a unique index).  The NOT-NULL enforcement
 * for new records coming through the Filament form is handled at the
 * application level (required rule in BoxResource::form()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('boxes')) {
            return;
        }

        // Skip entirely if either the canonical short-named index OR the
        // legacy auto-named index already covers this column — adding a second
        // unique index on the same column would cause a redundant-index error
        // on MariaDB and is unsupported by SQLite.
        if (
            Schema::hasIndex('boxes', 'boxes_barcode_uq')
            || Schema::hasIndex('boxes', 'boxes_barcode_unique')
        ) {
            return;
        }

        Schema::table('boxes', static function (Blueprint $table): void {
            $table->unique('barcode', 'boxes_barcode_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('boxes')) {
            return;
        }

        if (Schema::hasIndex('boxes', 'boxes_barcode_uq')) {
            Schema::table('boxes', static function (Blueprint $table): void {
                $table->dropIndex('boxes_barcode_uq');
            });
        }
    }
};
