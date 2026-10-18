<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-10-18: the Digitised column of the documents sheet holds the
 * link to the digitised copy (167 vHMML reading-room links), not one of the
 * VHMML / NRA / none values. The import dropped every link. The link is kept
 * here; `digitised` still says where the copy is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->string('digitised_url', 500)->nullable()->after('digitised');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t): void {
            $t->dropColumn('digitised_url');
        });
    }
};
