<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document flags (RFQ §3.1.12 — replace spreadsheet colour-coding).
 *
 * The legacy spreadsheet used row-level colour highlights (blue, green,
 * yellow, purple, pink) to mark documents needing attention. That mechanism
 * is invisible to filtering / reporting / search and impossible to audit.
 *
 * This table replaces it with structured, typed, audit-trailed issue flags:
 *  - `type`: which kind of attention is needed (review, duplicate, damage…),
 *  - `severity`: how loud the alert should be (info → warning → critical),
 *  - `status`: workflow position (open → acknowledged → resolved / dismissed),
 *  - operator notes: free text plus an optional structured `context` JSON
 *    (e.g. `{"duplicate_of": 42, "fields": ["identifier"]}`),
 *  - full provenance: who flagged, who resolved, when, and a resolution note.
 *
 * Multi-tenant: every row carries a denormalised `repository_id` (mirrored
 * from the parent Document on `creating`) so the cross-tenant RepositoryScope
 * filter can apply a single indexed predicate without a JOIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_flags', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_id')->index();
            $table->foreign('document_id', 'doc_flags_doc_fk')
                ->references('id')->on('documents')->cascadeOnDelete();

            // Mirrored from documents.repository_id so the RepositoryScope can
            // filter without a join. Nullable + nullOnDelete so we never orphan
            // a flag if the tenant row is wiped (the document's own cascade
            // would have removed the flag first anyway — this is defence in
            // depth for direct repository deletes).
            $table->unsignedBigInteger('repository_id')->nullable()->index();
            $table->foreign('repository_id', 'doc_flags_repo_fk')
                ->references('id')->on('repositories')->nullOnDelete();

            // Keep this list in sync with App\Models\DocumentFlag::TYPES.
            // The last five map the RFQ App.2-xviii legacy colour codes
            // (Pink/Orange/Grey/Red/Yellow; Brown == barcode_issue above).
            $table->enum('type', [
                'needs_review',
                'missing_data',
                'duplicate_suspect',
                'damaged',
                'restoration_needed',
                'wrongly_catalogued',
                'authority_mismatch',
                'barcode_issue',
                'disinfestation_overdue',
                'entry_issue',
                'location_check',
                'not_disinfested_onsite',
                'mould_treatment',
                'fragment_sorted',
                'other',
            ])->index();

            $table->enum('severity', ['info', 'warning', 'critical'])
                ->default('warning')
                ->index();

            $table->enum('status', ['open', 'acknowledged', 'resolved', 'dismissed'])
                ->default('open')
                ->index();

            $table->string('title', 200);
            $table->text('description')->nullable();

            // Structured payload — small JSON document describing the issue
            // in a machine-readable way (e.g. linked duplicate, affected fields,
            // expected vs actual values).
            $table->json('context')->nullable();

            $table->unsignedBigInteger('flagged_by_user_id')->nullable();
            $table->foreign('flagged_by_user_id', 'doc_flags_flagged_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            // Defaults to now() at the DB layer; the model and tests can still
            // override this for back-fills.
            $table->timestamp('flagged_at')->useCurrent()->index();

            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->foreign('resolved_by_user_id', 'doc_flags_resolved_by_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // Composite indexes for the two most common access patterns:
            //  (1) "open flags on document X" — Document detail timeline
            //  (2) "open flags by type within tenant" — alerts dashboard
            $table->index(['document_id', 'status'], 'doc_flags_doc_status_idx');
            $table->index(['repository_id', 'status', 'type'], 'doc_flags_tenant_status_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_flags');
    }
};
