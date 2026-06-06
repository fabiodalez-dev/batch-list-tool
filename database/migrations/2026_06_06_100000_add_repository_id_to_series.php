<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave D1 — Series gets a nullable repository_id.
 *
 * Allows series to be scoped to a single repository (or kept global when NULL).
 * Cross-engine (MariaDB + SQLite), idempotent, no ->after().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('series', 'repository_id')) {
            Schema::table('series', function (Blueprint $table): void {
                $table->unsignedBigInteger('repository_id')->nullable();
                $table->index('repository_id', 'series_repo_idx');
            });
        }

        // FK in a separate ALTER, wrapped in try/catch for idempotent re-run.
        try {
            Schema::table('series', function (Blueprint $table): void {
                $table->foreign('repository_id', 'series_repo_fk')
                    ->references('id')
                    ->on('repositories')
                    ->nullOnDelete();
            });
        } catch (Throwable $e) {
            Log::warning('add_repository_id_to_series: FK add skipped/failed (likely already present)', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('series', 'repository_id')) {
            Schema::table('series', function (Blueprint $table): void {
                try {
                    $table->dropForeign('series_repo_fk');
                } catch (Throwable) {
                }
                $table->dropIndex('series_repo_idx');
                $table->dropColumn('repository_id');
            });
        }
    }
};
