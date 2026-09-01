<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema audit (#9, Medium — can fail a save in prod).
 *
 * boxes.barcode_status was relaxed from ENUM('IN','OUT','PERM_OUT') to
 * VARCHAR(16) in 2026_05_30_910200 ("EXPAND, never restrict") so operators can
 * add barcode statuses through the lookup CRUD without a schema change. But the
 * audit-trail mirror box_barcode_history.previous_status / new_status stayed a
 * fixed 3-value ENUM. The box `updated` observer inserts the raw status into
 * those columns un-normalised, so assigning a newly-added status to a box hits
 * MySQL/MariaDB strict-mode error 1265 ("Data truncated") on an otherwise valid
 * save. Relax the mirror to match the live column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // SQLite: the columns are already plain TEXT — nothing to relax.
        }

        if (Schema::hasColumn('box_barcode_history', 'previous_status')) {
            DB::statement('ALTER TABLE box_barcode_history MODIFY COLUMN previous_status VARCHAR(16) NULL');
        }
        if (Schema::hasColumn('box_barcode_history', 'new_status')) {
            DB::statement('ALTER TABLE box_barcode_history MODIFY COLUMN new_status VARCHAR(16) NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Reverting to the fixed ENUM only succeeds if no out-of-set value was
        // written in the meantime — acceptable for a rollback of a fresh deploy.
        if (Schema::hasColumn('box_barcode_history', 'previous_status')) {
            DB::statement("ALTER TABLE box_barcode_history MODIFY COLUMN previous_status ENUM('IN','OUT','PERM_OUT') NULL");
        }
        if (Schema::hasColumn('box_barcode_history', 'new_status')) {
            DB::statement("ALTER TABLE box_barcode_history MODIFY COLUMN new_status ENUM('IN','OUT','PERM_OUT') NULL");
        }
    }
};
