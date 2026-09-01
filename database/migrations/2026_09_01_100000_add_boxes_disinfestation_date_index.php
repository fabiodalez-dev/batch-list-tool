<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema audit (Low, #10): the Disinfestation Cycle report filters
 * (`disinfestation_date IS NULL OR <= ?`) and orders by boxes.disinfestation_date,
 * but the column was never indexed while documents.disinfestation_date was.
 * Mirror the documents index so the report avoids a scan + filesort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boxes', function (Blueprint $table): void {
            $table->index('disinfestation_date', 'boxes_disinfestation_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('boxes', function (Blueprint $table): void {
            $table->dropIndex('boxes_disinfestation_date_index');
        });
    }
};
