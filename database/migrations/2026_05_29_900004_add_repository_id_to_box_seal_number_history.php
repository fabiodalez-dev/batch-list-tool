<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `repository_id` to box_seal_number_history for multi-tenant scoping,
 * mirroring box_barcode_history (RFQ §3.5.1).
 *
 * Boxes themselves carry no `repository_id` column — tenancy is derived via
 * `boxes.batch_id → batches.repository_id`. We mirror that value onto each
 * history row so the RepositoryScope can filter the append-only log directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_seal_number_history', function (Blueprint $table) {
            $table->unsignedBigInteger('repository_id')->nullable()->after('box_id')->index();
            $table->foreign('repository_id', 'box_seal_number_history_repo_fk')
                ->references('id')->on('repositories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('box_seal_number_history', function (Blueprint $table) {
            $table->dropForeign('box_seal_number_history_repo_fk');
            $table->dropColumn('repository_id');
        });
    }
};
