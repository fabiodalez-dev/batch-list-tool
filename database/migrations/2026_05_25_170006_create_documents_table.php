<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 64)->index();        // R1, R12, R1-V12, etc.
            $table->string('document_type', 64)->nullable();
            $table->foreignId('series_id')->constrained()->restrictOnDelete();
            $table->foreignId('accession_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('current_box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repository_id')->constrained()->restrictOnDelete();
            $table->string('volume_label', 64)->nullable();
            $table->date('dates_start')->nullable();
            $table->date('dates_end')->nullable();
            $table->integer('dates_year_start')->nullable();  // for legacy "1607-1629" format
            $table->integer('dates_year_end')->nullable();
            $table->date('disinfestation_date')->nullable();
            $table->json('extra')->nullable();                // spatie/laravel-schemaless-attributes
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['repository_id', 'series_id']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
