<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $t): void {
            $t->boolean('provenance_unknown')->default(false)->after('parent_box_id');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', fn (Blueprint $t) => $t->dropColumn('provenance_unknown'));
    }
};
