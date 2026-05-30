<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_user', function (Blueprint $t) {
            $t->string('role')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('repository_user', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
