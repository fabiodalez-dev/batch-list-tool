<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->enum('box_type', ['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC'])->index();
            $table->string('box_number', 32);
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->string('barcode', 64)->nullable()->unique();
            $table->enum('barcode_status', ['IN', 'OUT', 'PERM_OUT'])->default('IN');
            $table->date('disinfestation_date')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'box_number']);
        });

        // RFQ rule #5: PERM_OUT requires disinfestation_date
        // RFQ rule #4: MAV/STVC only for legacy (cannot be created new — enforced at app level + this DB check helps as a safety net for is_legacy)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE boxes ADD CONSTRAINT chk_boxes_permout_requires_disinfestation CHECK (barcode_status != 'PERM_OUT' OR disinfestation_date IS NOT NULL)");
            DB::statement("ALTER TABLE boxes ADD CONSTRAINT chk_boxes_legacy_types CHECK (box_type NOT IN ('MAV', 'STVC') OR is_legacy = 1)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('boxes');
    }
};
