<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave C2.5 — track PAST volume numbers alongside past identifiers.
 *
 * The client needs to search a document by a previous identifier AND a
 * previous volume number. `document_identifier_history` already stores the
 * identifier transition; this migration adds the (nullable) volume columns so
 * a single history row can carry both the previous identifier and the volume
 * number the document held at that time.
 *
 * MariaDB-safe: plain `Schema::table` add, NO `->after()`, idempotent
 * `hasColumn` guards, no raw CHECK / DROP CONSTRAINT. Indexed for the
 * past-volume search (`whereHas` on the history relation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_identifier_history', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_identifier_history', 'previous_volume')) {
                $table->string('previous_volume', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('document_identifier_history', 'new_volume')) {
                $table->string('new_volume', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_identifier_history', function (Blueprint $table): void {
            if (Schema::hasColumn('document_identifier_history', 'previous_volume')) {
                $table->dropColumn('previous_volume');
            }
            if (Schema::hasColumn('document_identifier_history', 'new_volume')) {
                $table->dropColumn('new_volume');
            }
        });
    }
};
