<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave C1.3 — "Add a new field Notary Accession Number ex: 2025-124".
 *
 * Adds a nullable, free-form-ish accession number (validated app-side against
 * the YYYY-NNN mask) to accessions. Nullable so existing rows keep loading and
 * saving without a number.
 *
 * Cross-engine safe: plain Schema::table ALTER, no ->after(), idempotent via
 * hasColumn().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accessions', 'accession_number')) {
            Schema::table('accessions', function (Blueprint $table) {
                $table->string('accession_number', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accessions', 'accession_number')) {
            Schema::table('accessions', function (Blueprint $table) {
                $table->dropColumn('accession_number');
            });
        }
    }
};
