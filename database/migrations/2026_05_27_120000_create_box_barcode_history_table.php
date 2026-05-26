<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Box barcode history (RFQ §3.1.5 — full audit trail).
     *
     * Persists every change to a Box's `barcode` and `barcode_status` so that:
     *  - the legacy "Barcode RAS 1/2/3/4" + "Status 1/2/3/4" repeating columns
     *    from the spreadsheet are represented as a proper append-only timeline,
     *  - a Filament RelationManager can render the chronological transitions,
     *  - searching for a previous barcode returns the current box.
     *
     * Multi-tenant: every row carries a `repository_id` mirrored from the
     * Box's batch (Boxes themselves have no direct `repository_id` column —
     * tenancy is derived via `boxes.batch_id → batches.repository_id`).
     *
     * Explicit foreign-key constraint names are used because MySQL 9 chokes
     * on Laravel's auto-generated names that exceed the 64-char limit
     * (see PR #37).
     */
    public function up(): void
    {
        Schema::create('box_barcode_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('box_id')->index();
            $table->foreign('box_id', 'box_barcode_history_box_fk')
                ->references('id')->on('boxes')->cascadeOnDelete();

            $table->string('previous_barcode', 64)->index();
            $table->string('new_barcode', 64)->nullable()->index();

            // Status snapshot at the time of the transition — mirrors the
            // legacy "Status 1/2/3/4" spreadsheet columns. Nullable because
            // older rows may have been imported without a status capture.
            $table->enum('previous_status', ['IN', 'OUT', 'PERM_OUT'])->nullable();
            $table->enum('new_status', ['IN', 'OUT', 'PERM_OUT'])->nullable();

            $table->timestamp('changed_at')->useCurrent()->index();

            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->foreign('changed_by_user_id', 'box_barcode_history_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->string('reason', 255)->nullable();

            $table->unsignedBigInteger('repository_id')->nullable()->index();
            $table->foreign('repository_id', 'box_barcode_history_repo_fk')
                ->references('id')->on('repositories')->nullOnDelete();

            $table->timestamps();

            // Composite index for the most common query: timeline of a box.
            $table->index(['box_id', 'changed_at'], 'box_barcode_history_box_changed_idx');
        });

        // Fulltext index for free-text lookup on previous_barcode — MySQL only.
        // Guarded so SQLite (used in CI / :memory: tests) doesn't break.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE box_barcode_history '
                . 'ADD FULLTEXT INDEX box_barcode_history_prev_ft (previous_barcode)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('box_barcode_history');
    }
};
