<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_seal_number_history');
        if (Schema::hasColumn('documents', 'seal_number')) {
            Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('seal_number'));
        }
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->string('seal_number')->nullable());
    }
};
