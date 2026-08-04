<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client feedback (Maria Pia / Charlene, 2026-08-04): boxes need a Tracking
 * Note separate from the general Note. Until now relocation notes were appended
 * to boxes.notes, which conflated the two. The document already has a dedicated
 * `tracking` column; this gives boxes an equivalent field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $t): void {
            $t->text('tracking_note')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', fn (Blueprint $t) => $t->dropColumn('tracking_note'));
    }
};
