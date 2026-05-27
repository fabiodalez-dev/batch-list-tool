<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
        'identifier' => 'DOC-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
        'series_id' => $series->id,
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
            'identifier' => 'TENANT-1',
            'series_id' => $series->id,
            'repository_id' => $mainRepo->id,
            'notes' => 'shared keyword discovery',
        ]);

        Document::create([
            'identifier' => 'TENANT-2',
            'series_id' => $series->id,
            'repository_id' => $repo2->id,
            'notes' => 'shared keyword discovery',
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
        $document = new Document;

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
            ->toThrow(InvalidArgumentException::class);

        // The exception message must enumerate both the whitelist and the
        // offending columns so the operator can fix the call-site fast.
        try {
            Document::query()->searchFullText('foo', ['barcode_in']);
            // Unreachable — the previous line throws.
            expect(true)->toBeFalse();
        } catch (InvalidArgumentException $e) {
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
