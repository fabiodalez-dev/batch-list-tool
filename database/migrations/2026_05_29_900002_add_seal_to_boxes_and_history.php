<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $t) {
            $t->string('seal_number')->nullable()->after('barcode');
        });

        Schema::create('box_seal_number_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('box_id')->constrained('boxes')->cascadeOnDelete();
            $t->string('old_value')->nullable();
            $t->string('new_value')->nullable();
            $t->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('changed_at')->useCurrent();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('box_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_seal_number_history');
        Schema::table('boxes', fn (Blueprint $t) => $t->dropColumn('seal_number'));
    }
};
