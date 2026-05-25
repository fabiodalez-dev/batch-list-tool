<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('batch_number')->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['MAIN_COLLECTION', 'NOTARY_ACCESSION'])->default('MAIN_COLLECTION');
            $table->foreignId('repository_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // RFQ validation rule #1: batch 33, 34, 36 forbidden for new records
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE batches ADD CONSTRAINT chk_batches_forbidden_numbers CHECK (batch_number NOT IN (33, 34, 36))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
