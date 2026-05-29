<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('preferred_page_size')->default(25)->after('default_repository_id');
            $table->string('locale')->nullable()->after('preferred_page_size');
            $table->string('timezone')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['preferred_page_size', 'locale', 'timezone']);
        });
    }
};
