<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentType;
use App\Models\Practice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB safeguard (Task 8): the "wipe but always repopulate" tool.
 *
 * `migrate:fresh` on its own leaves the dev DB empty — no admin to log in
 * with, no sample data to click through. This command always re-seeds the
 * minimum login data (InitialDataSeeder) and, by default, re-imports the RFQ
 * sample spreadsheets, then backfills the document_type / practice lookup
 * tables from the freshly imported documents. The result is a known-good,
 * never-empty database after a destructive refresh.
 *
 * Safety rails:
 *   - Aborts outright on the production environment.
 *   - Confirms before running on any non-local environment (unless --force).
 *   - Never runs in tests against a real DB (the suite uses SQLite :memory:).
 */
class RefreshSafe extends Command
{
    protected $signature = 'db:refresh-safe
        {--seed : (kept for symmetry; InitialDataSeeder always runs)}
        {--samples=true : Re-import the RFQ sample spreadsheets after the fresh}
        {--force : Skip the interactive confirmation prompt}';

    protected $description = 'migrate:fresh then ALWAYS repopulate (admin seed + sample import + lookup backfill) so the dev DB is never left empty.';

    public function handle(): int
    {
        // Never allow a destructive wipe on production.
        if ($this->getLaravel()->environment('production')) {
            $this->error('db:refresh-safe is refused on the production environment.');

            return self::FAILURE;
        }

        // Confirm on any non-local environment unless forced.
        if (! $this->option('force') && ! $this->getLaravel()->environment('local')) {
            if (! $this->confirm(
                "This will DROP ALL TABLES on the [{$this->getLaravel()->environment()}] environment and repopulate. Continue?"
            )) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        $this->warn('Running migrate:fresh (all tables dropped) ...');
        $this->call('migrate:fresh', ['--force' => true]);

        // Always re-seed the bare-minimum login data so someone can log in.
        $this->info('Seeding InitialDataSeeder (admin login + roles) ...');
        $this->call('db:seed', ['--class' => 'InitialDataSeeder', '--force' => true]);

        // Default: re-import the RFQ sample spreadsheets.
        if ($this->wantsSamples()) {
            $this->info('Importing RFQ sample spreadsheets (nra:import-samples) ...');
            $this->call('nra:import-samples');

            // After a fresh + import, the document_types / practices lookup
            // tables are empty (their seeding migration ran on an empty
            // documents table). Backfill them from the imported documents.
            $this->backfillLookups();
        }

        $this->info('db:refresh-safe complete — database wiped and repopulated.');

        return self::SUCCESS;
    }

    /**
     * Whether the --samples option is enabled. Defaults to true; accepts
     * common falsey strings (false/0/no/off) to disable.
     */
    private function wantsSamples(): bool
    {
        return filter_var($this->option('samples'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Backfill the document_type / practice controlled-vocabulary tables from
     * the DISTINCT values present in `documents` after a fresh import.
     *
     * Mirrors the seeding logic in the create-migration so a fresh+import
     * leaves the lookups populated rather than empty.
     */
    private function backfillLookups(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        $types = DB::table('documents')
            ->whereNotNull('document_type')
            ->selectRaw('TRIM(document_type) AS document_type')
            ->whereRaw("TRIM(document_type) != ''")
            ->distinct()
            ->pluck('document_type')
            ->all();

        foreach ($types as $name) {
            DocumentType::query()->firstOrCreate(
                ['name' => mb_substr((string) $name, 0, 100)],
                ['is_active' => true],
            );
        }

        $practices = DB::table('documents')
            ->whereNotNull('practice')
            ->selectRaw('TRIM(practice) AS practice')
            ->whereRaw("TRIM(practice) != ''")
            ->distinct()
            ->pluck('practice')
            ->all();

        foreach ($practices as $name) {
            Practice::query()->firstOrCreate(
                ['name' => mb_substr((string) $name, 0, 100)],
                ['is_active' => true],
            );
        }

        $this->info(sprintf(
            '  → Lookups backfilled: %d document types, %d practices.',
            DocumentType::query()->count(),
            Practice::query()->count(),
        ));
    }
}
