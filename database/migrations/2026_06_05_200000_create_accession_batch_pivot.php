<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B / B1 — Create the accession_batch pivot table.
 *
 * Replaces the accessions.batch_id FK (1:N) with a proper N:N join table so
 * a single accession can span multiple batches and a batch can be compiled
 * from multiple accessions (e.g. Batch 50 = wills from several notaries).
 *
 * Cross-engine: works on MariaDB and SQLite.
 * Idempotent: guarded by Schema::hasTable; safe to run on any installation.
 * Index names are explicitly short (< 64 chars, MariaDB limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accession_batch')) {
            return;
        }

        Schema::create('accession_batch', static function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('accession_id');
            $table->unsignedBigInteger('batch_id');

            $table->timestamps();

            // Short-named unique pair constraint (MariaDB 64-char limit)
            $table->unique(['accession_id', 'batch_id'], 'acc_batch_uq');

            // Individual FK indexes with explicit short names
            $table->index('accession_id', 'acc_batch_acc_id_idx');
            $table->index('batch_id', 'acc_batch_bat_id_idx');

            $table->foreign('accession_id', 'acc_batch_acc_fk')
                ->references('id')->on('accessions')
                ->cascadeOnDelete();

            $table->foreign('batch_id', 'acc_batch_bat_fk')
                ->references('id')->on('batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accession_batch');
    }
};
