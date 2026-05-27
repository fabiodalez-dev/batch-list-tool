<?php

use App\Models\ReportTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report templates (RFQ §3.2.2 — "Save report templates").
 *
 * Persists a user-defined snapshot of filter state, column visibility and
 * sort order against one of the canned report pages (DocumentsByBatch,
 * DocumentsByCreator, DocumentsBySeries, PendingDisinfestation,
 * BoxMovementHistory, FlagsByType — see {@see ReportTemplate::SOURCES}).
 *
 * Each row is owned by a user but may be shared with the rest of the
 * tenant via `is_shared` so common saved views (e.g. "open critical
 * flags this month") can be a one-click bookmark for the whole team.
 *
 * Multi-tenancy: `repository_id` is nullable so super_admin / admin can
 * save GLOBAL templates that survive across repositories; non-privileged
 * users get the column stamped from their `default_repository_id` by the
 * BelongsToRepository trait on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('repository_id')->nullable();
            $table->foreign('repository_id')
                ->references('id')->on('repositories')->nullOnDelete();

            $table->string('name', 191);
            $table->string('description', 500)->nullable();
            $table->string('source', 64);
            // Which underlying report class this template targets:
            // 'documents' | 'documents_by_batch' | 'documents_by_creator' |
            // 'documents_by_series' | 'pending_disinfestation' | 'flags_by_type'
            $table->json('filters'); // serialised filter state (array of key=>value)
            $table->json('columns')->nullable(); // optional column visibility array
            $table->json('sort')->nullable(); // sort column + direction
            $table->boolean('is_shared')->default(false); // visible to all users in the repo
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'source']);
            $table->index(['repository_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_templates');
    }
};
