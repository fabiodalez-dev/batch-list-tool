<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NAF client feedback (Charlene, 2026-08-01) — supersedes the earlier RFQ
 * App.1 #5 rigidity for the legacy bulk box import. The feedback is later than
 * the RFQ, so it governs: the historical boxes being loaded pre-date the modern
 * data-quality rules and cannot satisfy them.
 *
 * Three schema loosenings:
 *   1. add `current_box_type` — the physical container type (RAS Box, Big Brown
 *      Box, Small Brown Box, Standard Blue Box, …), distinct from the archival
 *      `box_type` (RAS/IN_SITU/NRA/MAV/STVC). The client's source sheet carries
 *      it in a "Current Box" column.
 *   2. `box_type` NOT NULL → NULL — the two catch-all boxes ("Unknown", "NULL")
 *      carry no box type and were being halted.
 *   3. drop chk_boxes_permout_requires_disinfestation — a PERM_OUT box no longer
 *      requires a disinfestation_date (legacy perm-out/destroyed boxes often
 *      have none recorded). The box-location requirement for PERM_OUT is dropped
 *      in the app guards (BoxImporter/Box/BoxResource) — it was never a DB CHECK.
 *
 * MySQL/MariaDB-guarded for the column-type + CHECK changes; SQLite keeps
 * box_type as a plain string and never had the CHECK, so only the ADD COLUMN
 * runs there.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cross-driver: the new physical-container column.
        if (! Schema::hasColumn('boxes', 'current_box_type')) {
            Schema::table('boxes', function (Blueprint $table): void {
                $table->string('current_box_type', 64)->nullable()->after('box_type');
            });
        }

        // box_type NOT NULL → NULL, cross-driver. Laravel's native change()
        // rebuilds the SQLite table (dropping the old enum CHECK, so the value
        // set is app-governed there too) and issues a MODIFY on MySQL. The
        // "Unknown"/"NULL" catch-all boxes carry no box type.
        Schema::table('boxes', function (Blueprint $table): void {
            $table->string('box_type', 32)->nullable()->change();
        });

        // RFQ App.1 #5 CHECK (PERM_OUT requires disinfestation_date) was only
        // ever added on MySQL — drop it there. Superseded by the 2026-08-01
        // client feedback.
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropCheckIfExists('boxes', 'chk_boxes_permout_requires_disinfestation');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // Re-add the CHECK only if the data still satisfies it (best-effort);
            // then restore NOT NULL. Both are wrapped so a down() on diverged
            // data doesn't hard-fail.
            try {
                DB::statement("ALTER TABLE boxes ADD CONSTRAINT chk_boxes_permout_requires_disinfestation CHECK (barcode_status != 'PERM_OUT' OR disinfestation_date IS NOT NULL)");
            } catch (QueryException) {
                // Data no longer satisfies it — leave the CHECK off.
            }
            if (Schema::hasColumn('boxes', 'box_type')) {
                try {
                    DB::statement('ALTER TABLE boxes MODIFY COLUMN box_type VARCHAR(32) NOT NULL');
                } catch (QueryException) {
                    // Null box_types exist — keep the column nullable.
                }
            }
        }

        if (Schema::hasColumn('boxes', 'current_box_type')) {
            Schema::table('boxes', function (Blueprint $table): void {
                $table->dropColumn('current_box_type');
            });
        }
    }

    private function dropCheckIfExists(string $table, string $constraint): void
    {
        try {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        } catch (QueryException) {
            // Constraint absent (fresh DB or already dropped) — fine.
        }
    }
};
