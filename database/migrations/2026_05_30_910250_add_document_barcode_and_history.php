<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 7b (RFQ Wave 2 expansion) — per-document barcode value + audited history.
 *
 * Beyond the box-level barcode (contract Task 7), each document may carry its
 * OWN optional barcode value for individual labelling. Every change is recorded
 * in `document_barcode_history` (append-only log), mirroring the
 * `box_seal_number_history` pattern introduced in Task 7.
 *
 * The document's custody STATUS still comes from its box (Task 7 mirror).
 * This migration adds only the per-document barcode VALUE + history.
 *
 * `repository_id` on the history table mirrors the parent document's
 * `repository_id` directly (documents carry that column, unlike boxes which
 * derive it via batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->string('barcode')->nullable()->after('barcode_status');
        });

        Schema::create('document_barcode_history', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->unsignedBigInteger('repository_id')->nullable()->after('document_id')->index();
            $t->foreign('repository_id', 'doc_barcode_history_repo_fk')
                ->references('id')->on('repositories')->nullOnDelete();
            $t->string('old_value')->nullable();
            $t->string('new_value')->nullable();
            $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_at')->useCurrent();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_barcode_history');
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('barcode'));
    }
};
