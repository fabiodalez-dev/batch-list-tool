<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-08-18 (#1) — legacy box-movement TIMELINE.
 *
 * The box-provenance columns already captured verbatim on `documents`
 * (in_situ_box_1/2/3, ras_box_2, plus the current RAS box) let us reconstruct
 * the chain of boxes a document passed through. The client cannot supply real
 * dates for those historical moves, so a legacy move is stored with a NULL
 * `movement_date`, flagged via `date_source`, and ordered deterministically by
 * `sequence`.
 *
 *   - `movement_date` NOT NULL → NULLABLE, so an undated legacy move can exist.
 *   - `date_source` marks whether the row's date is real ('recorded') or an
 *     undated legacy import ('legacy_import'). Backfills existing rows to
 *     'recorded' (every pre-existing move has a real date).
 *   - `sequence` preserves the order within one document's chain even when
 *     every legacy move shares a NULL date.
 *
 * Cross-driver: Laravel 13's native ->change() rebuilds the SQLite table (used
 * by the test suite) and issues a MODIFY on MySQL/MariaDB (dev + prod), so no
 * doctrine/dbal dependency and no raw driver-specific SQL is needed. The
 * existing (document_id, movement_date) and (movement_date) indexes survive a
 * nullability change untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_movements', function (Blueprint $table): void {
            $table->dateTime('movement_date')->nullable()->change();
        });

        Schema::table('box_movements', function (Blueprint $table): void {
            $table->string('date_source', 20)->default('recorded')->after('movement_date');
            $table->unsignedSmallInteger('sequence')->nullable()->after('date_source');
        });
    }

    public function down(): void
    {
        Schema::table('box_movements', function (Blueprint $table): void {
            $table->dropColumn('sequence');
            $table->dropColumn('date_source');
        });

        // Restore NOT NULL. Any legacy rows carrying a NULL movement_date would
        // block this; acceptable for a reversal (dev only) — the up() path is
        // what ships.
        Schema::table('box_movements', function (Blueprint $table): void {
            $table->dateTime('movement_date')->nullable(false)->change();
        });
    }
};
