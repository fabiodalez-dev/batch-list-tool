<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\ImportProfile;
use App\Models\ReportTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import profiles — reusable column-mapping presets for {@see ImportWizard}.
 *
 * RFQ §3.1.3 (Bulk Import v2) ships an Onboarding Wizard with auto-guess
 * column mapping (name → guess() aliases → fuzzy match). The wizard now
 * lets the operator hand-tune the mapping when their spreadsheet uses
 * non-standard headers (e.g. "Creator Name" instead of "given_names",
 * "Name of Inputter" instead of "created_by", etc).
 *
 * Hand-tuned mappings can be saved as an Import Profile, so the next time
 * the same operator imports a file with the same layout they pick the
 * profile from a dropdown in step 1 of the wizard and skip the manual
 * column-by-column work.
 *
 * Ownership / sharing mirrors the {@see ReportTemplate} model:
 *   - every profile has an owner (`user_id`);
 *   - `is_shared = true` makes it visible to all users in the same
 *     repository (RepositoryScope still hides cross-tenant rows);
 *   - super_admin / admin bypass the RepositoryScope and see global rows.
 *
 * Per-profile telemetry (`last_used_at`, `use_count`) is bumped by
 * {@see ImportProfile::markUsed()} every time the wizard
 * actually runs an import that started from a profile — this lets the
 * wizard sort the "starting profile" dropdown by most-recently-used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_profiles', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('repository_id')->nullable();

            $table->string('name', 191);
            $table->string('description', 500)->nullable();

            // Matches a key of {@see App\Filament\Pages\ImportWizard::IMPORTERS}:
            // 'series' | 'authorities' | 'batches' | 'boxes' | 'documents'
            $table->string('import_type', 32);

            // Map shape: [importer_field => excel_header_string|null].
            // Stored as JSON so the wizard can hydrate it back into Select state.
            $table->json('column_map');

            // Optional per-profile aliases — extends the class-level SYNONYMS
            // table for one specific spreadsheet layout (e.g. "Inputter" → "created_by").
            $table->json('synonyms')->nullable();

            $table->boolean('is_shared')->default(false);

            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('repository_id')
                ->references('id')->on('repositories')->nullOnDelete();

            $table->index(['user_id', 'import_type']);
            $table->index(['repository_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_profiles');
    }
};
