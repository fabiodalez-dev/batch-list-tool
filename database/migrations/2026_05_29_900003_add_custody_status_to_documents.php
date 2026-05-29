<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->string('custody_status')->default('in_box')->after('barcode_status');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE documents ADD CONSTRAINT chk_documents_custody_status CHECK (custody_status IN ('in_box','not_in_box','mounted_no_box'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT chk_documents_custody_status');
        }
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('custody_status'));
    }
};
