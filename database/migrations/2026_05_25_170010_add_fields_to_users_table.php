<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_repository_id')->nullable()->after('email')->constrained('repositories')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('default_repository_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['default_repository_id']);
            $table->dropColumn(['default_repository_id', 'is_active', 'deleted_at']);
        });
    }
};
