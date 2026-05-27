<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ-2026-06 §3.1.11 — Controlled vocabularies for `document_type` and
 * `practice` (the two free-text strings that had no lookup table).
 *
 * Strategy: additive only. The existing free-text columns stay; this
 * migration creates two reference tables and seeds them with the
 * DISTINCT values already present in `documents`. Operators can then
 * manage the canonical list through the new Filament Resources; the
 * Document form switches from free-text input to a searchable Select
 * (still letting an admin add a fresh value via createOption).
 *
 * No FK is added on `documents` yet — that would block the M3 legacy
 * import if NAF's live data contains values we don't yet have rows
 * for. The FK can land in a follow-up migration once the controlled
 * list is settled with NAF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('practices', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed from existing distinct values so admins start with a
        // populated list and don't have to retype 30 entries by hand.
        if (! Schema::hasTable('documents')) {
            return;
        }

        $now = now();

        // TRIM at the SQL level: legacy data has trailing spaces + whitespace-
        // only cells which would otherwise create dupes like 'Register' +
        // 'Register ' as two separate lookup rows.
        $types = DB::table('documents')
            ->whereNotNull('document_type')
            ->selectRaw('TRIM(document_type) AS document_type')
            ->whereRaw("TRIM(document_type) != ''")
            ->distinct()
            ->pluck('document_type')
            ->all();
        foreach ($types as $name) {
            DB::table('document_types')->insertOrIgnore([
                'name' => mb_substr((string) $name, 0, 100),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $practices = DB::table('documents')
            ->whereNotNull('practice')
            ->selectRaw('TRIM(practice) AS practice')
            ->whereRaw("TRIM(practice) != ''")
            ->distinct()
            ->pluck('practice')
            ->all();
        foreach ($practices as $name) {
            DB::table('practices')->insertOrIgnore([
                'name' => mb_substr((string) $name, 0, 100),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practices');
        Schema::dropIfExists('document_types');
    }
};
