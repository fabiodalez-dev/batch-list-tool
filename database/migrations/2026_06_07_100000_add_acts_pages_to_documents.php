<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave F / DECISION F2 — Add `number_of_acts` and `pages_folios` to documents.
 *
 * Both fields preserve the raw operator-entered cell value as a plain string
 * (do NOT coerce to int — historical data is dirty and may contain notes,
 * ranges, or non-numeric qualifiers such as "approx. 120" or "n/a").
 *
 * Cross-engine: works on MariaDB 10.11 and SQLite.
 * Idempotent: guarded by Schema::hasColumn; safe to re-run.
 * No ->after(): not valid on SQLite; cross-engine constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'number_of_acts')) {
            Schema::table('documents', static function (Blueprint $table): void {
                $table->string('number_of_acts', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('documents', 'pages_folios')) {
            Schema::table('documents', static function (Blueprint $table): void {
                $table->string('pages_folios', 128)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'number_of_acts')) {
            Schema::table('documents', static function (Blueprint $table): void {
                $table->dropColumn('number_of_acts');
            });
        }

        if (Schema::hasColumn('documents', 'pages_folios')) {
            Schema::table('documents', static function (Blueprint $table): void {
                $table->dropColumn('pages_folios');
            });
        }
    }
};
