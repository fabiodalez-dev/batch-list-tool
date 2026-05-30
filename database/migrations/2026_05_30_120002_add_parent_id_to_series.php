<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave C1.4 — hierarchical, multi-level Series (Top-Level Series,
 * sub-series, sub-sub-series, … no fixed depth).
 *
 * Adjacency-list model: a nullable self-referencing parent_id. Cycle
 * prevention is enforced app-side (SeriesResource form excludes self +
 * descendants, plus a server-side closure rule) — NO raw CHECK constraint.
 *
 * Cross-engine safe:
 *  - column added in a plain Schema::table ALTER, no ->after() (breaks SQLite);
 *  - the self FK is added in a SEPARATE Schema::table call (follow-up ALTER
 *    with foreign() works on SQLite, MySQL and MariaDB);
 *  - both steps guarded for idempotency so a partial MariaDB deploy re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('series', 'parent_id')) {
            Schema::table('series', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->index('parent_id');
            });

            // Self FK in its own ALTER so SQLite (and MariaDB) accept it.
            Schema::table('series', function (Blueprint $table) {
                $table->foreign('parent_id')
                    ->references('id')
                    ->on('series')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('series', 'parent_id')) {
            Schema::table('series', function (Blueprint $table) {
                // Drop FK first (driver-portable: Laravel resolves the
                // conventional constraint name "series_parent_id_foreign").
                $table->dropForeign(['parent_id']);
                $table->dropIndex(['parent_id']);
                $table->dropColumn('parent_id');
            });
        }
    }
};
