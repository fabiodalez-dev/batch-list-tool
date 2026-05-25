<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();          // R, REG, RWL, OWL, O
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_wills_series')->default(false);  // RFQ rule: wills must go to batch 50
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
