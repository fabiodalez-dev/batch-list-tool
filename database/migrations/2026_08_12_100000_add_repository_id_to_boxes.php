<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-08-12 (Charlene): give `boxes` an OWN `repository_id` so a box
 * that has no batch is still visible.
 *
 * Until now boxes derived tenancy purely from batch_id → batches.repository_id
 * (ThroughBatchRepositoryScope). But the form / model allow batch-less boxes
 * (batch is required for RAS only; IN_SITU / NRA / MAV / STVC / MUS may have
 * none), and such a box matched no batch row in the scope's whereExists → it
 * was created "successfully" yet invisible to everyone. Charlene's In-Situ /
 * legacy boxes genuinely never have a batch.
 *
 * Fix: a nullable own repository_id. It is populated ONLY for batch-less boxes
 * (on create / import, from the active repository); batched boxes keep it null
 * and continue to resolve via their batch, so their behaviour is unchanged and
 * no backfill of the existing rows is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->foreignId('repository_id')
                ->nullable()
                ->after('batch_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('repository_id');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', function (Blueprint $table) {
            $table->dropIndex(['repository_id']);
            $table->dropConstrainedForeignId('repository_id');
        });
    }
};
