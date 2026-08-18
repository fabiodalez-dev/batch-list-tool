<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-08-18 (Charlene) — new document columns for the document import:
 *   #26 Temporary Identifier — free text, UNIQUE per document (nullable-unique:
 *       many NULLs are allowed on MySQL/MariaDB; the DB index is the hard
 *       guarantee, the importer also validates + catches the QueryException).
 *   #28 Citation Reference — free text, no constraints.
 *
 * (#27 "Conservation Object Reference Number" is NOT a new column — it is the
 * existing `object_reference_number`, only relabelled; no schema change.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('temporary_identifier', 191)->nullable()->after('object_reference_number');
            $table->text('citation_reference')->nullable()->after('temporary_identifier');
            $table->unique('temporary_identifier', 'documents_temporary_identifier_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_temporary_identifier_unique');
            $table->dropColumn(['temporary_identifier', 'citation_reference']);
        });
    }
};
