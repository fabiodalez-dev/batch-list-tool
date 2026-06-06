<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave D4 — Rename `documents.volume_label` to `volume_number`.
 *
 * Cross-engine: Laravel 13 natively supports renameColumn on both MySQL/MariaDB
 * and SQLite (no doctrine/dbal required). Idempotent: only renames if
 * volume_label exists AND volume_number does not.
 *
 * No ->after(): not valid on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'volume_label')
            && ! Schema::hasColumn('documents', 'volume_number')
        ) {
            Schema::table('documents', function ($table): void {
                $table->renameColumn('volume_label', 'volume_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'volume_number')
            && ! Schema::hasColumn('documents', 'volume_label')
        ) {
            Schema::table('documents', function ($table): void {
                $table->renameColumn('volume_number', 'volume_label');
            });
        }
    }
};
