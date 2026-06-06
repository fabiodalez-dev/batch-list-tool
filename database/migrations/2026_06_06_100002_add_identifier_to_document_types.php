<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave D2 — Add nullable `identifier` to document_types.
 *
 * The unique index uses a partial (non-NULL) unique constraint on MariaDB/MySQL
 * via a filtered index on SQLite. However, both engines naturally allow multiple
 * NULL values in a unique index — NULL != NULL by ANSI SQL — so a plain
 * nullable unique() column is the right tool and is cross-engine safe.
 *
 * Cross-engine, idempotent, no ->after().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('document_types', 'identifier')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table): void {
            $table->string('identifier', 64)->nullable();
            $table->unique('identifier', 'doctype_identifier_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('document_types', 'identifier')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropUnique('doctype_identifier_uq');
            $table->dropColumn('identifier');
        });
    }
};
