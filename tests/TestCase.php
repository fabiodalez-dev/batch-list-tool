<?php

namespace Tests;

use App\Support\CustomFields\CustomFieldResolver;
use App\Support\LocationBreadcrumbCache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Flush request-memoised static caches before each test so that tests
     * running in the same process do not bleed state between scenarios.
     *
     * This is necessary for any class that maintains a static array as a
     * "request-level" memo (e.g. CustomFieldResolver), because PHP static
     * variables survive across test cases within one phpunit/pest process
     * even when RefreshDatabase truncates the database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        CustomFieldResolver::flush();
        // Request-scoped static memo keyed by location id — RefreshDatabase
        // resets the DB (and reuses ids) but not the static cache, so flush it
        // per test to prevent a stale breadcrumb bleeding across scenarios.
        LocationBreadcrumbCache::flush();

        // Schema audit (#14): SQLite's built-in LOWER() folds only ASCII, while
        // the MySQL/MariaDB prod database (utf8mb4) folds accented Latin. The
        // import resolvers compare LOWER(column) against an mb_strtolower'd
        // needle, so accented uppercase values resolve in prod but not under the
        // SQLite test driver. Override SQLite's LOWER() with a Unicode-aware
        // implementation so the suite exercises the same case-folding as prod.
        // Kept in the test bootstrap (not AppServiceProvider) so it never touches
        // the production boot path or forces an early connection. mb_strtolower
        // is a strict superset of ASCII lower — it can only improve correctness.
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            if (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('LOWER', static fn (?string $value): ?string => $value === null ? null : mb_strtolower($value), 1);
            }
        }
    }
}
