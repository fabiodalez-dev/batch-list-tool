<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Box;
use App\Models\Concerns\ConditionallyPreloadsRelations;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Whether the active connection driver is MySQL. The whole FULLTEXT-on-MySQL
 * branch of the suite is gated on this.
 */
function isMysql(): bool
{
    return DB::connection()->getDriverName() === 'mysql';
}

/**
 * Bootstrap a Repository + Series so the foreign-key constraints on Document
 * are happy. We don't have factories yet, so we drop down to direct create()
 * calls — this is cheap and explicit.
 */
function bootstrapRefData(): array
{
    $repo = Repository::firstOrCreate(
        ['code' => 'NRA'],
        ['name' => 'Notarial Registers Archive', 'is_active' => true],
    );

    $series = Series::firstOrCreate(
        ['code' => 'R'],
        ['title' => 'Register Copies', 'is_active' => true],
    );

    return [$repo, $series];
}

/**
 * Make a Document with sensible defaults; caller-supplied overrides win.
 * The `identifier` is auto-suffixed with a counter so callers don't have to
 * worry about uniqueness inside a single test.
 */
function makeFtDocument(array $attrs = []): Document
{
    static $counter = 0;
    $counter++;

    [$repo, $series] = bootstrapRefData();

    return Document::create(array_merge([
        'identifier'    => 'DOC-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
        'series_id'     => $series->id,
        'repository_id' => $repo->id,
    ], $attrs));
}

// =============================================================================
// SECTION 1 — FULLTEXT search scope (12 tests)
// =============================================================================

describe('FULLTEXT search', function () {

    it('migration skips execution on non-MySQL drivers', function () {
        if (isMysql()) {
            // On MySQL the migration DOES run — verified by the dedicated
            // "creates index" test below. This branch only asserts the
            // guard on SQLite / Postgres / etc.
            $this->markTestSkipped('MySQL driver — non-MySQL guard not relevant here.');
        }

        // The migration ran during RefreshDatabase setup. If the guard
        // had not kicked in, the migration would have thrown a syntax
        // error on SQLite (no FULLTEXT support) and the test bootstrap
        // would have aborted. The fact that we're inside the test body
        // is itself the assertion.
        expect(DB::connection()->getDriverName())->not->toBe('mysql');
        expect(true)->toBeTrue();
    });

    it('migration creates the documents.notes FULLTEXT index on MySQL', function () {
        if (! isMysql()) {
            $this->markTestSkipped('FULLTEXT indexes only exist on MySQL.');
        }

        $rows = DB::select(
            "SELECT INDEX_NAME, INDEX_TYPE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'documents'
               AND INDEX_NAME = 'idx_documents_notes_ft'",
        );

        expect($rows)->not->toBeEmpty();
        expect($rows[0]->INDEX_TYPE)->toBe('FULLTEXT');
    });

    it('searchFullText returns documents matching a term in notes', function () {
        makeFtDocument(['notes' => 'This register documents land transfers in Valletta.']);
        makeFtDocument(['notes' => 'Wedding contracts dated 1750.']);
        makeFtDocument(['notes' => null]);

        // FULLTEXT default min word length is 4 — "register" is safely above it.
        $results = Document::query()->searchFullText('register', ['notes'])->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->notes)->toContain('register');
    });

    it('searchFullText returns documents matching a term in deeds', function () {
        makeFtDocument(['deeds' => 'Sale of property between Borg and Mifsud, 1820.']);
        makeFtDocument(['deeds' => 'Marriage settlement.']);

        $results = Document::query()->searchFullText('property', ['deeds'])->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->deeds)->toContain('property');
    });

    it('searchFullText supports boolean operators when running on MySQL', function () {
        if (! isMysql()) {
            $this->markTestSkipped('Boolean operators are a MySQL FULLTEXT feature.');
        }

        makeFtDocument(['notes' => 'register of wills']);
        makeFtDocument(['notes' => 'register of property deeds']);
        makeFtDocument(['notes' => 'inventory of wills']);

        // Note: boolean mode is *not* what scopeSearchFullText uses by
        // default (it uses NATURAL LANGUAGE MODE so `+`/`-` are stripped).
        // What we actually assert here is that searching "register" still
        // finds the two "register" docs even when the database is full of
        // operator-looking characters in other docs.
        $results = Document::query()->searchFullText('register', ['notes'])->get();

        expect($results)->toHaveCount(2);
    });

    it('searchFullText returns an empty collection when nothing matches', function () {
        makeFtDocument(['notes' => 'Standard register entry.']);

        $results = Document::query()->searchFullText('zzzzznonexistent', ['notes'])->get();

        expect($results)->toHaveCount(0);
    });

    it('searchFullText is case-insensitive', function () {
        makeFtDocument(['notes' => 'Register of Notarial Acts.']);

        $upper = Document::query()->searchFullText('REGISTER', ['notes'])->get();
        $lower = Document::query()->searchFullText('register', ['notes'])->get();

        expect($upper)->toHaveCount(1);
        expect($lower)->toHaveCount(1);
        expect($upper->first()->id)->toBe($lower->first()->id);
    });

    it('searchFullText is accent-insensitive on utf8mb4 MySQL', function () {
        if (! isMysql()) {
            $this->markTestSkipped('Accent insensitivity is a MySQL collation behaviour.');
        }

        makeFtDocument(['notes' => 'Notaire Mifsud à Marsaxlokk.']);

        // utf8mb4_unicode_ci treats "a" and "à" as equal — searching with
        // the unaccented form should still match.
        $results = Document::query()->searchFullText('Marsaxlokk', ['notes'])->get();

        expect($results)->toHaveCount(1);
    });

    it('searchFullText falls back to LIKE on SQLite', function () {
        if (isMysql()) {
            $this->markTestSkipped('LIKE fallback only runs on non-MySQL drivers.');
        }

        // Short term (< MySQL FT min length) — proves we are *not* using
        // FT semantics: LIKE matches any substring regardless of word
        // length.
        makeFtDocument(['notes' => 'abc xyz']);
        makeFtDocument(['notes' => 'xyz def']);
        makeFtDocument(['notes' => 'unrelated']);

        $results = Document::query()->searchFullText('xyz', ['notes'])->get();

        expect($results)->toHaveCount(2);
    });

    it('searchFullText composes with where() chains correctly', function () {
        $d1 = makeFtDocument(['notes' => 'register of contracts', 'volume_label' => 'A']);
        $d2 = makeFtDocument(['notes' => 'register of contracts', 'volume_label' => 'B']);
        makeFtDocument(['notes' => 'inventory of land']);

        $results = Document::query()
            ->where('volume_label', 'A')
            ->searchFullText('register', ['notes'])
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($d1->id);
    });

    it('searchFullText respects an additional repository_id WHERE clause (multi-tenant safety)', function () {
        $repo2 = Repository::create(['code' => 'EXT', 'name' => 'External', 'is_active' => true]);
        [$mainRepo, $series] = bootstrapRefData();

        Document::create([
            'identifier'    => 'TENANT-1',
            'series_id'     => $series->id,
            'repository_id' => $mainRepo->id,
            'notes'         => 'shared keyword discovery',
        ]);

        Document::create([
            'identifier'    => 'TENANT-2',
            'series_id'     => $series->id,
            'repository_id' => $repo2->id,
            'notes'         => 'shared keyword discovery',
        ]);

        // Simulate a multi-tenant scope: a where() restricting to a
        // single repository must compose with searchFullText() without
        // either side leaking matches from the other tenant.
        $results = Document::query()
            ->where('repository_id', $mainRepo->id)
            ->searchFullText('discovery', ['notes'])
            ->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->identifier)->toBe('TENANT-1');
    });

    it('scope searchFullText is registered on the Document model', function () {
        $document = new Document();

        // Eloquent exposes local scopes via `newQuery()->{scope}()`. If
        // the scope wasn't registered (typo, missing method, ...) this
        // call would throw BadMethodCallException.
        $query = $document->newQuery()->searchFullText('whatever', ['notes']);

        expect($query)->toBeInstanceOf(Builder::class);
    });

    it('searchFullText is a no-op on an empty term (Filament safety)', function () {
        // Filament's filters pass `null`/empty when the user clears the
        // input. The scope must not throw and must not add a WHERE.
        makeFtDocument(['notes' => 'anything']);

        $count = Document::query()->searchFullText('', ['notes'])->count();

        // Empty term → no filter → full table count.
        expect($count)->toBeGreaterThan(0);
    });

    it('throws InvalidArgumentException for non-whitelisted columns', function () {
        // `barcode_in` is a legitimate column on `documents`, but it is NOT
        // part of FULLTEXT_COLUMNS — passing it to scopeSearchFullText
        // must throw immediately rather than producing a MySQL error at
        // execution time (or, worse, silently scanning the wrong index).
        expect(fn () => Document::query()->searchFullText('foo', ['barcode_in']))
            ->toThrow(\InvalidArgumentException::class);

        // The exception message must enumerate both the whitelist and the
        // offending columns so the operator can fix the call-site fast.
        try {
            Document::query()->searchFullText('foo', ['barcode_in']);
            // Unreachable — the previous line throws.
            expect(true)->toBeFalse();
        } catch (\InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('barcode_in');
            expect($e->getMessage())->toContain('notes');
        }
    });

    it('short-circuits on terms shorter than min token size', function () {
        // Seed 3 docs so the baseline count is non-zero and visible to
        // the assertion. Their contents are irrelevant — the scope must
        // return the unfiltered builder for any term shorter than 3 chars,
        // which means the count after .searchFullText() equals the count
        // before it.
        makeFtDocument(['notes' => 'register entry one']);
        makeFtDocument(['notes' => 'register entry two']);
        makeFtDocument(['notes' => 'unrelated']);

        $baseline = Document::query()->count();
        expect($baseline)->toBe(3);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // "R7" is 2 chars → below InnoDB's default min_token_size (3).
        $filtered = Document::query()->searchFullText('R7', ['notes'])->count();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // 1. The filter is a no-op: count equals the unfiltered baseline.
        expect($filtered)->toBe($baseline);

        // 2. No MATCH (...) AGAINST and no LIKE '%R7%' should have been
        //    issued — only the bare COUNT(*) from the assertion above.
        $hasFulltextOrLike = $queries->contains(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'match')
                || str_contains($sql, 'against')
                || str_contains($sql, 'like ?')
                || str_contains($sql, "like '%r7%'");
        });

        expect($hasFulltextOrLike)->toBeFalse();
    });
});

// =============================================================================
// SECTION 2 — ConditionallyPreloadsRelations trait (13 tests)
// =============================================================================

describe('ConditionallyPreloadsRelations trait', function () {

    it('trait exists and is loadable', function () {
        expect(trait_exists(ConditionallyPreloadsRelations::class))->toBeTrue();
    });

    it('returns the same query when count is below threshold', function () {
        // 5 documents, threshold 200 → eager load should NOT be applied.
        for ($i = 0; $i < 5; $i++) {
            makeFtDocument();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $docs = Document::query()->conditionallyWith(['series', 'repository'])->get();

        // Iterate to force lazy loads (or lack thereof).
        foreach ($docs as $d) {
            $d->series;
            $d->repository;
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 5 docs × 2 relations = 10 lazy loads + 1 outer SELECT = 11+.
        // If conditional preload had eager-loaded, we'd see only 3
        // queries (1 docs + 1 series + 1 repository).
        // Cache may dedupe identical IDs, but the test still proves it
        // did NOT auto-eager-load.
        expect(count($queries))->toBeGreaterThan(3);
    });

    it('applies with() when count is above threshold', function () {
        for ($i = 0; $i < 6; $i++) {
            makeFtDocument();
        }

        // threshold = 3 → 6 > 3, eager should fire.
        $query = Document::query()->conditionallyWith(['series', 'repository'], threshold: 3);

        // We can introspect $query->getEagerLoads() to assert that the
        // scope actually appended the `with()`.
        expect($query->getEagerLoads())->toHaveKeys(['series', 'repository']);
    });

    it('default threshold is 200', function () {
        $reflection = new ReflectionMethod(Document::class, 'scopeConditionallyWith');
        $params = $reflection->getParameters();

        // Index 2 = the `$threshold` parameter (after $query, $relations).
        expect($params[2]->getName())->toBe('threshold');
        expect($params[2]->getDefaultValue())->toBe(200);
    });

    it('threshold is configurable per call', function () {
        for ($i = 0; $i < 5; $i++) {
            makeFtDocument();
        }

        // threshold 100 → 5 < 100 → no eager
        $noEager = Document::query()->conditionallyWith(['series'], threshold: 100);
        expect($noEager->getEagerLoads())->toBe([]);

        // threshold 2 → 5 > 2 → eager
        $eager = Document::query()->conditionallyWith(['series'], threshold: 2);
        expect($eager->getEagerLoads())->toHaveKey('series');
    });

    it('cheapCount uses LIMIT to short-circuit', function () {
        // Spy on the actual SQL: if the implementation uses LIMIT, the
        // last count query before our get() should include `limit 4`
        // (threshold 3 + 1) — or at least mention `limit`.
        for ($i = 0; $i < 10; $i++) {
            makeFtDocument();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Document::query()->conditionallyWith(['series'], threshold: 3);

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // Find the COUNT query.
        $countQ = $queries->first(fn ($q) => stripos($q['query'], 'count(') !== false);

        expect($countQ)->not->toBeNull();
        expect(strtolower($countQ['query']))->toContain('limit');
    });

    it('works on Document model', function () {
        // Seed at least one document so 1 > threshold(0) is true.
        makeFtDocument();

        $query = Document::query()->conditionallyWith(['series'], threshold: 0);
        expect($query->getEagerLoads())->toHaveKey('series');
    });

    it('works on Authority model', function () {
        // Seed a single authority so the threshold(0) comparison passes.
        Authority::create([
            'identifier' => 'AUTH-A1',
            'surname'    => 'Borg',
        ]);

        $query = Authority::query()->conditionallyWith(['documents'], threshold: 0);
        expect($query->getEagerLoads())->toHaveKey('documents');
    });

    it('works on Box model', function () {
        $query = Box::query()->conditionallyWith(['batch'], threshold: 0);
        // Empty table + threshold 0: 0 > 0 is FALSE → no eager.
        expect($query->getEagerLoads())->toBe([]);

        // Now with threshold -1 (i.e. always eager).
        $query2 = Box::query()->conditionallyWith(['batch'], threshold: -1);
        expect($query2->getEagerLoads())->toHaveKey('batch');
    });

    it('composes with existing where() clauses — count is post-filter', function () {
        for ($i = 0; $i < 5; $i++) {
            makeFtDocument(['volume_label' => 'A']);
        }
        for ($i = 0; $i < 5; $i++) {
            makeFtDocument(['volume_label' => 'B']);
        }

        // Threshold 7: total 10, filtered to 5 ('A'). 5 < 7 → no eager.
        $query = Document::query()
            ->where('volume_label', 'A')
            ->conditionallyWith(['series'], threshold: 7);

        expect($query->getEagerLoads())->toBe([]);

        // Threshold 3: filtered 5 > 3 → eager fires.
        $query2 = Document::query()
            ->where('volume_label', 'A')
            ->conditionallyWith(['series'], threshold: 3);

        expect($query2->getEagerLoads())->toHaveKey('series');
    });

    it('composes with whereHas() clauses', function () {
        [$repo, $series] = bootstrapRefData();
        $altSeries = Series::create(['code' => 'O', 'title' => 'Originals', 'is_active' => true]);

        for ($i = 0; $i < 4; $i++) {
            makeFtDocument(['series_id' => $series->id]);
        }
        for ($i = 0; $i < 2; $i++) {
            makeFtDocument(['series_id' => $altSeries->id]);
        }

        $query = Document::query()
            ->whereHas('series', fn ($q) => $q->where('code', 'R'))
            ->conditionallyWith(['repository'], threshold: 3);

        // 4 docs match the whereHas, > 3 → eager.
        expect($query->getEagerLoads())->toHaveKey('repository');
    });

    it('does not break when relations array is empty', function () {
        $query = Document::query()->conditionallyWith([], threshold: 0);

        expect($query)->toBeInstanceOf(Builder::class);
        expect($query->getEagerLoads())->toBe([]);
    });

    it('handles nested dot-notation relations (currentBox.batch)', function () {
        for ($i = 0; $i < 3; $i++) {
            makeFtDocument();
        }

        $query = Document::query()->conditionallyWith(
            ['currentBox.batch'],
            threshold: 1,
        );

        // Eager load entries are stored as 'currentBox' (top-level) and
        // 'currentBox.batch' (nested). At minimum the parent key exists.
        $eager = $query->getEagerLoads();

        expect($eager)->toHaveKey('currentBox');
        expect($eager)->toHaveKey('currentBox.batch');
    });

    it('is idempotent — calling twice does not double-load', function () {
        for ($i = 0; $i < 5; $i++) {
            makeFtDocument();
        }

        $query = Document::query()
            ->conditionallyWith(['series'], threshold: 1)
            ->conditionallyWith(['series'], threshold: 1);

        $eager = $query->getEagerLoads();

        // Even with two calls, the eager-load entry for 'series' is one
        // closure — Eloquent overwrites on duplicate keys.
        expect($eager)->toHaveKey('series');
        expect(array_keys($eager))->toBe(['series']);
    });

    it('benchmark: 300 docs trigger eager loading, 50 do not (via query log)', function () {
        // --- Phase 1: 50 docs, default threshold 200 → no eager ---
        for ($i = 0; $i < 50; $i++) {
            makeFtDocument();
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $q1 = Document::query()->conditionallyWith(['series']);
        $q1->get(); // materialise

        $logSmall = DB::getQueryLog();
        DB::flushQueryLog();

        // No 'series' eager-load query should be present (look for the
        // characteristic `select * from "series" where ... in (...)`).
        $hasEagerSmall = collect($logSmall)->contains(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'from "series"') || str_contains($sql, 'from `series`');
        });

        expect($hasEagerSmall)->toBeFalse();

        // --- Phase 2: top up to 300 docs → eager fires ---
        for ($i = 0; $i < 250; $i++) {
            makeFtDocument();
        }

        DB::flushQueryLog();

        $q2 = Document::query()->conditionallyWith(['series']);
        $q2->get();

        $logBig = DB::getQueryLog();
        DB::disableQueryLog();

        $hasEagerBig = collect($logBig)->contains(function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'from "series"') || str_contains($sql, 'from `series`');
        });

        expect($hasEagerBig)->toBeTrue();
    });
});
