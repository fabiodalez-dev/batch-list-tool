<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document identifier history (RFQ §3.1.5 — full audit trail).
     *
     * Persists every change to a Document's `identifier`, so that:
     *  - searching for a previous identifier returns the current document,
     *  - a Filament RelationManager can render the chronological timeline,
     *  - the global spotlight finds documents by former identifier.
     *
     * Multi-tenant: every row is scoped to a repository_id (per RFQ §3.5.1).
     */
    public function up(): void
    {
        Schema::create('document_identifier_history', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete()
                ->index();

            $table->string('previous_identifier', 64)->index();
            $table->string('new_identifier', 64)->nullable()->index();

            $table->timestamp('changed_at')->useCurrent()->index();

            $table->foreignId('changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason', 255)->nullable();

            $table->foreignId('repository_id')
                ->nullable()
                ->constrained('repositories')
                ->nullOnDelete()
                ->index();

            $table->timestamps();

            // Composite index for the most common query: timeline of a document.
            $table->index(['document_id', 'changed_at'], 'doc_id_history_doc_changed_idx');
        });

        // Fulltext index for free-text lookup on previous_identifier — MySQL only.
        // Guarded so SQLite (used in CI / :memory: tests) doesn't break.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE document_identifier_history '
                . 'ADD FULLTEXT INDEX doc_id_history_prev_ft (previous_identifier)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_identifier_history');
    }
};
