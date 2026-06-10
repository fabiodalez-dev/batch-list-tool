<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave D4 — Practice gets nullable identifier (for import) and
 * optional repository_id (a practice may belong to a repository).
 *
 * Cross-engine (MariaDB + SQLite), idempotent, no ->after().
 * Index and FK names are short (≤64 chars) for MariaDB compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('practices', 'identifier')) {
            Schema::table('practices', function (Blueprint $table): void {
                $table->string('identifier', 64)->nullable()->unique('practice_identifier_uq');
            });
        }

        if (! Schema::hasColumn('practices', 'repository_id')) {
            Schema::table('practices', function (Blueprint $table): void {
                $table->unsignedBigInteger('repository_id')->nullable();
                $table->index('repository_id', 'practice_repo_idx');
            });
        }

        // FK in a separate ALTER, wrapped in try/catch for idempotent re-run.
        try {
            Schema::table('practices', function (Blueprint $table): void {
                $table->foreign('repository_id', 'practice_repo_fk')
                    ->references('id')
                    ->on('repositories')
                    ->nullOnDelete();
            });
        } catch (Throwable $e) {
            Log::warning('add_identifier_and_repository_id_to_practices: FK add skipped/failed (likely already present)', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('practices', 'repository_id')) {
            Schema::table('practices', function (Blueprint $table): void {
                try {
                    $table->dropForeign('practice_repo_fk');
                } catch (Throwable) {
                }
                $table->dropIndex('practice_repo_idx');
                $table->dropColumn('repository_id');
            });
        }

        if (Schema::hasColumn('practices', 'identifier')) {
            Schema::table('practices', function (Blueprint $table): void {
                $table->dropColumn('identifier');
            });
        }
    }
};
