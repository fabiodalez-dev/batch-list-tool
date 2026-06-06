<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback1 Wave D1 — N:N pivot between document_types and series.
 *
 * Cross-engine (MariaDB + SQLite), idempotent (guarded by hasTable), no ->after().
 * FKs in a separate try/catch block following the established pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_type_series')) {
            Schema::create('document_type_series', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('document_type_id');
                $table->unsignedBigInteger('series_id');
                $table->timestamps();

                $table->unique(['document_type_id', 'series_id'], 'doctype_series_uq');
            });
        }

        // FKs in a separate ALTER so a partial deploy (table created, FKs not) re-adds them.
        try {
            Schema::table('document_type_series', function (Blueprint $table): void {
                $table->foreign('document_type_id', 'dts_doctype_fk')
                    ->references('id')
                    ->on('document_types')
                    ->cascadeOnDelete();
                $table->foreign('series_id', 'dts_series_fk')
                    ->references('id')
                    ->on('series')
                    ->cascadeOnDelete();
            });
        } catch (Throwable $e) {
            Log::warning('create_document_type_series: FKs add skipped/failed (likely already present)', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_type_series');
    }
};
