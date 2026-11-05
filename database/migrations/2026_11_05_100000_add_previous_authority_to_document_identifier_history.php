<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-11-05: when a document is attributed to another authority, keep
 * track of who it used to be attributed to ("Prev attributed Authority").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_identifier_history', function (Blueprint $table) {
            $table->unsignedBigInteger('previous_authority_id')->nullable()->after('new_volume')->index();
            $table->foreign('previous_authority_id', 'doc_id_history_prev_authority_fk')
                ->references('id')->on('authorities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_identifier_history', function (Blueprint $table) {
            $table->dropForeign('doc_id_history_prev_authority_fk');
            $table->dropColumn('previous_authority_id');
        });
    }
};
