<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave C1.2 — "Can we filter which creators worked as NTG ie: have
 * a NTG date associated?".
 *
 * Adds a nullable NTG (Notary to Government) date to authorities. Nullable so
 * existing rows are untouched; "worked as NTG" is then `ntg_date IS NOT NULL`.
 *
 * Cross-engine safe: plain Schema::table ALTER, no ->after() (breaks SQLite),
 * idempotent via hasColumn() so a partially-applied MariaDB deploy can re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('authorities', 'ntg_date')) {
            Schema::table('authorities', function (Blueprint $table) {
                $table->date('ntg_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('authorities', 'ntg_date')) {
            Schema::table('authorities', function (Blueprint $table) {
                $table->dropColumn('ntg_date');
            });
        }
    }
};
