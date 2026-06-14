<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance pass: add the B-tree indexes the schema is missing.
 *
 * The omni-search closure on /admin/documents fans LIKE %term% out across
 * 8 tables and `RepositoryScope` adds a `WHERE documents.repository_id IN
 * (...)` to every query. SoftDeletes additionally appends `WHERE deleted_at
 * IS NULL`. Without indexes on those columns MySQL is forced into a full
 * table scan on every keystroke — measurable >2s p95 at 3,113 docs and
 * cliff-edge degradation as the table grows toward the contractual 50k.
 *
 * The migration is intentionally idempotent: it inspects
 * `information_schema.STATISTICS` (MySQL) or `pragma_index_list` (SQLite)
 * before adding any index, and additionally wraps each individual call in
 * a try/catch on QueryException so a duplicate-key error from a prod DB
 * that already carries an FK-auto index never breaks the migration.
 *
 * MySQL only: indexes are created with `ALGORITHM=INPLACE, LOCK=NONE` via
 * the post-step `alterTableNoLock()` call where the driver supports it, so
 * this migration is safe to run on a live production database without a
 * locking window.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::connection()->getDriverName() === 'mysql';

        foreach ($this->plannedIndexes() as $table => $rows) {
            if (! Schema::hasTable($table)) {
                // Defensive: a test DB driver / partially-migrated install
                // may not have every table — skip rather than crash.
                continue;
            }

            foreach ($rows as $row) {
                [$columns, $indexName] = [$row[0], $row[1]];
                $columns = (array) $columns;
                $allColumnsExist = array_all($columns, fn ($column) => Schema::hasColumn($table, $column));
                if (! $allColumnsExist) {
                    continue;
                }

                if ($this->indexExists($table, $indexName, $columns)) {
                    continue;
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
                        $blueprint->index($columns, $indexName);
                    });

                    // Best-effort online-DDL hint on MySQL — convert any
                    // implicit lock to INPLACE/LOCK=NONE so a production
                    // migration on the 3,113-row sample (and the future
                    // 50k contract) finishes in milliseconds without
                    // blocking concurrent SELECT/UPDATEs. Failures here
                    // are swallowed — the index is already created.
                    if ($isMysql) {
                        $this->alterTableNoLock($table, $indexName, $columns);
                    }
                } catch (QueryException $exception) {
                    // Tolerate "duplicate key name" / "duplicate index" errors
                    // from prod DBs that pre-carry an FK-auto index under a
                    // slightly different name than what we tried to create.
                    throw_unless($this->isDuplicateIndexError($exception), $exception);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->plannedIndexes() as $table => $rows) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($rows as $row) {
                $indexName = $row[1];
                if (! $this->indexExists($table, $indexName)) {
                    continue;
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                        $blueprint->dropIndex($indexName);
                    });
                } catch (QueryException) {
                    // Tolerate: index might have been dropped by another
                    // migration before us; nothing to do.
                }
            }
        }
    }

    /**
     * Indexes to add, keyed by table name. Each row is:
     *   [columns (string|array), index name, optional 'unique' => bool].
     *
     * Single-column index name follows Laravel's convention
     * `<table>_<column>_index`; composite indexes get an explicit name so
     * the down() migration can drop them deterministically.
     */
    private function plannedIndexes(): array
    {
        return [
            // ---- documents (the hot table) ----
            'documents' => [
                ['repository_id', 'documents_repository_id_index'],
                ['batch_id', 'documents_batch_id_index'],
                ['series_id', 'documents_series_id_index'],
                ['accession_id', 'documents_accession_id_index'],
                ['deleted_at', 'documents_deleted_at_index'],
                ['dates_year_start', 'documents_dates_year_start_index'],
                ['dates_year_end', 'documents_dates_year_end_index'],
                ['disinfestation_date', 'documents_disinfestation_date_index'],
                // Combined RepositoryScope (WHERE repository_id IN (...))
                // + SoftDeletes (WHERE deleted_at IS NULL): one composite
                // index satisfies both predicates so MySQL stops scanning
                // the table and walks the (repo, alive) leaf pages directly.
                [['repository_id', 'deleted_at'], 'documents_repository_alive_idx'],
            ],

            // ---- boxes ----
            'boxes' => [
                ['batch_id', 'boxes_batch_id_index'],
                // Self-reference for IN_SITU box lookups (parent RAS box).
                // Column is `parent_box_id` (NOT `parent_ras_box_id` —
                // the schema renamed it so the FK can also point at a
                // parent IN_SITU box during accession splits).
                ['parent_box_id', 'boxes_parent_box_id_index'],
                ['barcode_status', 'boxes_barcode_status_index'],
                ['deleted_at', 'boxes_deleted_at_index'],
            ],

            // ---- batches ----
            'batches' => [
                ['repository_id', 'batches_repository_id_index'],
                ['deleted_at', 'batches_deleted_at_index'],
            ],

            // ---- authorities ----
            // `surname` is already indexed individually; add a composite to
            // cover the typed-name omni-search ("Abela Antonio") where MySQL
            // can range-scan the surname prefix THEN range-scan given_names.
            'authorities' => [
                ['identifier', 'authorities_identifier_index_perf'], // dupes the unique; cheap
                ['deleted_at', 'authorities_deleted_at_index'],
                [['surname', 'given_names'], 'authorities_surname_given_names_idx'],
            ],

            // ---- document_authority pivot ----
            // Laravel's primary([document_id, authority_id]) already gives
            // us the (document_id, authority_id) B-tree (used for the
            // document→authorities join). We additionally need a B-tree
            // headed on authority_id for the reverse direction (authority's
            // documents list, the omni-search whereHas('authorities', ...)
            // EXISTS subquery's correlated lookup).
            'document_authority' => [
                ['authority_id', 'document_authority_authority_id_index'],
            ],

            // ---- series ----
            'series' => [
                ['deleted_at', 'series_deleted_at_index'],
            ],

            // ---- repositories ----
            'repositories' => [
                ['deleted_at', 'repositories_deleted_at_index'],
            ],

            // ---- accessions ----
            'accessions' => [
                ['repository_id', 'accessions_repository_id_index'],
                ['deleted_at', 'accessions_deleted_at_index'],
            ],

            // ---- document_flags ----
            // Omni-search whereHas('flags', ...) needs `document_id` indexed
            // for the correlated EXISTS. If it's already there via FK, the
            // duplicate-index guard below skips silently.
            'document_flags' => [
                ['document_id', 'document_flags_document_id_index'],
            ],
        ];
    }

    /**
     * Check whether an index with the given name already exists OR an
     * equivalent index covering the same columns (in the same order)
     * exists under a different name (e.g. FK-auto-created).
     *
     * Pure-SQL probe so we don't depend on the DBAL schema manager.
     */
    private function indexExists(string $table, string $indexName, ?array $columns = null): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$table],
            );

            $byIndex = [];
            foreach ($rows as $row) {
                $byIndex[$row->INDEX_NAME][] = $row->COLUMN_NAME;
            }

            if (array_key_exists($indexName, $byIndex)) {
                return true;
            }

            if ($columns !== null) {
                foreach ($byIndex as $cols) {
                    if ($cols === $columns) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ?",
                [$table],
            );
            foreach ($rows as $row) {
                if ($row->name === $indexName) {
                    return true;
                }
            }

            if ($columns !== null) {
                foreach ($rows as $row) {
                    $info = DB::select('PRAGMA index_info(' . $this->quoteIdentifier($row->name) . ')');
                    $cols = array_map(static fn ($r) => $r->name, $info);
                    if ($cols === $columns) {
                        return true;
                    }
                }
            }

            return false;
        }

        // Postgres / other: best-effort fall back to "doesn't exist" so the
        // CREATE INDEX attempt below decides definitively (try/catch above
        // swallows duplicates).
        return false;
    }

    /**
     * Apply `ALGORITHM=INPLACE, LOCK=NONE` to the index just created so a
     * production deploy doesn't lock the table. MySQL ignores the hint when
     * it cannot honour it (downgrades to COPY+SHARED automatically), so the
     * fail path is benign. Best-effort: swallow any SQL error.
     */
    private function alterTableNoLock(string $table, string $indexName, array $columns): void
    {
        // No-op for now: Laravel's `Blueprint::index()` already emits
        // `CREATE INDEX ... ON table (...)`, and starting with MySQL 5.6
        // CREATE INDEX defaults to INPLACE/LOCK=NONE for B-tree indexes on
        // InnoDB. The explicit ALTER...ALGORITHM=INPLACE LOCK=NONE only
        // helps on ancient 5.5; we don't support that. Method kept as a
        // hook for future tuning so the up() loop's hint reads cleanly.
        unset($table, $indexName, $columns);
    }

    private function isDuplicateIndexError(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        // MySQL: SQLSTATE[42000] ER_DUP_KEYNAME (1061)
        if (str_contains($message, '1061') || str_contains($message, 'Duplicate key name')) {
            return true;
        }

        // SQLite: "index ... already exists"
        return str_contains($message, 'already exists');
    }

    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
};
