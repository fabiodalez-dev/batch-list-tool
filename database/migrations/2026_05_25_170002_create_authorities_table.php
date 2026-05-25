<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorities', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 32)->unique();              // R1, R12, R110, ...
            $table->string('alternative_identifier', 32)->nullable(); // MS511, MS523, ...
            $table->string('surname');
            $table->string('given_names')->nullable();
            $table->string('entity_type', 16)->default('PERSON');    // PERSON | INSTITUTION
            $table->integer('practice_dates_start')->nullable();     // year
            $table->integer('practice_dates_end')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('surname');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorities');
    }
};
