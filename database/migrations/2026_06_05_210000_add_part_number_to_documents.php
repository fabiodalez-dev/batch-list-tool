<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave C / DECISION 5 — Add `part_number` to documents.
 *
 * A notarial document may consist of multiple physical parts (e.g. cover,
 * main body, appendix). `part_number` records the ordinal or label of the
 * specific part being tracked (nullable: most documents are a single unit).
 *
 * Cross-engine: works on MariaDB and SQLite.
 * Idempotent: guarded by Schema::hasColumn; safe to re-run.
 * No ->after(): not valid on SQLite; cross-engine constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'part_number')) {
            return;
        }

        Schema::table('documents', static function (Blueprint $table): void {
            $table->string('part_number', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'part_number')) {
            return;
        }

        Schema::table('documents', static function (Blueprint $table): void {
            $table->dropColumn('part_number');
        });
    }
};
