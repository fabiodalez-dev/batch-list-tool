<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client 2026-11-10, Report 1 (RAS - NRA reconciliation): the operator uploads
 * the RAS export and the system lists where it does not tally with our RAS
 * boxes.
 *
 * The upload is read once, a row at a time, into ras_reconciliation_rows, with
 * the outcome of the comparison next to each row. The report page then only
 * pages through this table: a request never holds the 12,000-row export in
 * memory (the host stops a web request at about 64 MB).
 *
 * One run per repository is kept (the latest upload), so the operator can come
 * back to it and compare it again after fixing our records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ras_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            // null = uploaded while "All repositories" was active.
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->unsignedInteger('ras_rows')->default(0);
            $table->unsignedInteger('our_boxes')->default(0);
            $table->timestamp('compared_at')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'id']);
        });

        Schema::create('ras_reconciliation_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('ras_reconciliation_runs')->cascadeOnDelete();
            // Spreadsheet row number (1 = header); null for a row of ours only.
            $table->unsignedInteger('sheet_row')->nullable();

            // What RAS says.
            $table->string('ras_barcode', 64)->nullable();
            $table->string('ras_reference', 64)->nullable();   // as written, e.g. "ARK/054-4"
            $table->string('ras_batch', 32)->nullable();       // normalised: "4"
            $table->string('ras_box', 32)->nullable();         // normalised: "54", "9A"
            $table->string('ras_status_raw', 32)->nullable();  // "Perm Out"
            $table->string('ras_status', 16)->nullable();      // PERM_OUT / IN / OUT
            $table->date('ras_date')->nullable();
            $table->string('ras_container', 32)->nullable();   // PREMIUM / XLPREMIUM / CONTAINER

            // What we hold, at the time of the comparison.
            $table->unsignedBigInteger('box_id')->nullable()->index();
            $table->string('our_barcode', 64)->nullable();
            $table->boolean('our_barcode_replaced')->default(false);
            $table->string('our_batch', 32)->nullable();
            $table->string('our_box', 32)->nullable();
            $table->string('our_status', 16)->nullable();

            $table->string('outcome', 24);
            $table->boolean('status_mismatch')->default(false);
            $table->string('note')->nullable();

            $table->index(['run_id', 'outcome']);
            $table->index(['run_id', 'ras_barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ras_reconciliation_rows');
        Schema::dropIfExists('ras_reconciliation_runs');
    }
};
