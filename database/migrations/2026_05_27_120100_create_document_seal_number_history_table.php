<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document seal number history (RFQ §3.1.5 — full audit trail).
     *
     * Persists every change to a Document's `seal_number`, so that:
     *  - the chain-of-custody for physical seals is auditable end-to-end,
     *  - a Filament RelationManager can render the chronological timeline,
     *  - operators can investigate when / why a seal number was reassigned.
     *
     * Multi-tenant: every row is scoped to a repository_id (per RFQ §3.5.1).
     *
     * Mirrors the shape of `document_identifier_history` so the auditing
     * substrate stays consistent across all per-field history tables.
     */
    public function up(): void
    {
        Schema::create('document_seal_number_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_id')->index();
            $table->foreign('document_id', 'doc_seal_history_doc_fk')
                ->references('id')->on('documents')->cascadeOnDelete();

            $table->string('previous_seal_number', 50)->index();
            $table->string('new_seal_number', 50)->nullable()->index();

            $table->timestamp('changed_at')->useCurrent()->index();

            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->foreign('changed_by_user_id', 'doc_seal_history_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->string('reason', 255)->nullable();

            $table->unsignedBigInteger('repository_id')->nullable()->index();
            $table->foreign('repository_id', 'doc_seal_history_repo_fk')
                ->references('id')->on('repositories')->nullOnDelete();

            $table->timestamps();

            // Composite index for the most common query: timeline of a document.
            $table->index(['document_id', 'changed_at'], 'doc_seal_history_doc_changed_idx');
        });

        // Fulltext index for free-text lookup on previous_seal_number — MySQL only.
        // Guarded so SQLite (used in CI / :memory: tests) doesn't break.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE document_seal_number_history '
                . 'ADD FULLTEXT INDEX doc_seal_history_prev_ft (previous_seal_number)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_seal_number_history');
    }
};
