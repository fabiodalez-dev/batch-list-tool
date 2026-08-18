<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-08-18 (Charlene) #12 / #17 — give documents an optional FK to the
 * Document Types and Practices lookups, so a document can be LINKED to them by
 * identifier/name while the existing free-text `document_type` / `practice`
 * columns keep being written (dual-write, zero behavioural change for the
 * reports/filters that read the text). The FKs are nullable and match-only on
 * import — a value that doesn't resolve leaves the FK null and the text stands,
 * so a row never fails on these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_type_id')
                ->nullable()
                ->after('document_type')
                ->constrained('document_types')
                ->nullOnDelete();

            $table->foreignId('practice_id')
                ->nullable()
                ->after('practice')
                ->constrained('practices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_type_id');
            $table->dropConstrainedForeignId('practice_id');
        });
    }
};
