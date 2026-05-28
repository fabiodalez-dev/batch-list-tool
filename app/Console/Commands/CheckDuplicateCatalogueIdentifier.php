<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preflight for the UNIQUE-on-`documents.catalogue_identifier` constraint
 * introduced by the 2026_05_27_170100_tighten_document_lookups migration.
 *
 * The migration carries a NULL-distinct unique index. If the legacy
 * Batch_List_Sample import produces two non-null rows sharing the same
 * `catalogue_identifier` (e.g. a digitisation typo, or two volumes that
 * happen to share the surname-volume key), the schema change ITSELF would
 * fail at `ALTER TABLE` time on prod.
 *
 * This command answers, before the M3 data-migration milestone:
 *
 *   - Are there duplicates today?
 *   - If yes, which `catalogue_identifier` values and how many rows?
 *
 * Exit codes:
 *   0 → no duplicates, M3 import is safe.
 *   1 → duplicates found; operator must resolve before re-running.
 *
 * Soft-deleted documents are EXCLUDED — the unique constraint is enforced
 * by MySQL against live rows only, so reading through the soft-delete scope
 * mirrors what `ALTER TABLE` will actually see.
 */
class CheckDuplicateCatalogueIdentifier extends Command
{
    protected $signature = 'nra:check-duplicate-catalogue-identifier
                            {--include-trashed : Also count soft-deleted documents (forensic mode)}
                            {--limit=50 : Cap on number of duplicate groups to print}';

    protected $description = 'Preflight: find documents sharing a catalogue_identifier before the UNIQUE rollout.';

    public function handle(): int
    {
        // DB::table() bypasses the SoftDeletingScope global scope entirely,
        // returning ALL rows (including soft-deleted) by default. To mirror
        // what `ALTER TABLE … ADD UNIQUE` will actually see, we add an
        // explicit `whereNull('deleted_at')` UNLESS `--include-trashed` is
        // set (forensic widened view).
        $query = DB::table('documents')
            ->selectRaw('catalogue_identifier, COUNT(*) AS row_count')
            ->whereNotNull('catalogue_identifier')
            ->groupBy('catalogue_identifier')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('row_count');

        if (! $this->option('include-trashed')) {
            $query->whereNull('deleted_at');
        }

        $limit = max(1, (int) $this->option('limit'));
        $duplicates = $query->limit($limit + 1)->get();

        if ($duplicates->isEmpty()) {
            $this->info('OK — no duplicate `catalogue_identifier` values found. UNIQUE migration is safe.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Found %d duplicate catalogue_identifier group(s)%s. Resolve before re-running M3 import.',
            $duplicates->count(),
            $duplicates->count() > $limit ? " (showing first {$limit})" : '',
        ));

        $this->table(
            ['catalogue_identifier', 'row_count'],
            $duplicates->take($limit)->map(static fn (object $row): array => [
                (string) $row->catalogue_identifier,
                (int) $row->row_count,
            ])->all(),
        );

        return self::FAILURE;
    }
}
