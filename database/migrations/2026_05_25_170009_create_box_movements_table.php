<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->foreignId('to_box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->dateTime('movement_date');
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['document_id', 'movement_date']);
            $table->index('movement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_movements');
    }
};
