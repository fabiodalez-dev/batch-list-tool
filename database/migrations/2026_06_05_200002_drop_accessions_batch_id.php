<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B / B3 — Drop the legacy accessions.batch_id column after the data
 * has been copied to the accession_batch pivot (migration B2).
 *
 * Single-install policy: no backward-compat preservation required.
 *
 * Cross-engine notes:
 *   - MariaDB: the FK constraint must be dropped before the column can be
 *     dropped. We drop it by its auto-generated name
 *     `accessions_batch_id_foreign` (set by ->constrained() in the original
 *     create_accessions_table migration). The drop is guarded: if the
 *     constraint is already absent we skip it silently.
 *   - SQLite: does not enforce FK names and dropForeign is a no-op; the
 *     schema builder emulates dropColumn via table-rebuild, so we just call
 *     dropColumn directly.
 *
 * Idempotent: guarded by Schema::hasColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accessions')) {
            return;
        }

        if (! Schema::hasColumn('accessions', 'batch_id')) {
            // Column already removed — nothing to do.
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // On SQLite >= 3.35 `ALTER TABLE DROP COLUMN` is used natively,
            // but it refuses to drop a column that is still referenced by a FK
            // constraint in the table definition. To work around this we call
            // dropForeign (column-array form) AND dropColumn inside the same
            // Schema::table closure: Laravel then falls back to the full
            // table-rebuild path (compileAlter) which omits the FK from the
            // reconstructed DDL before the column disappears.
            Schema::table('accessions', static function (Blueprint $table): void {
                $table->dropForeign(['batch_id']);
                $table->dropColumn('batch_id');
            });
        } else {
            // MariaDB/MySQL: drop FK by its auto-generated name first, then
            // the column in a separate DDL statement. Guard each step.
            try {
                Schema::table('accessions', static function (Blueprint $table): void {
                    $table->dropForeign('accessions_batch_id_foreign');
                });
            } catch (Throwable) {
                // Constraint absent or already removed — continue.
            }

            Schema::table('accessions', static function (Blueprint $table): void {
                $table->dropColumn('batch_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accessions')) {
            return;
        }

        if (Schema::hasColumn('accessions', 'batch_id')) {
            return;
        }

        Schema::table('accessions', static function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
