<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Document;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Coverage for `nra:check-duplicate-catalogue-identifier`, the preflight
 * that protects the M3 import from blowing up on the
 * UNIQUE-on-catalogue_identifier index introduced by
 * 2026_05_27_170100_tighten_document_lookups.
 *
 * The trick: we must INSERT two rows sharing the same catalogue_identifier
 * on a database that ALREADY has the unique index. We sidestep the index by
 * INSERTing the duplicate via `DB::table()->insert()` AFTER `RefreshDatabase`
 * has run — except on MySQL/Postgres the unique constraint would still
 * reject it. In the test suite we run against SQLite (test driver per
 * phpunit.xml), where the migration created a partial unique index whose
 * NULL-distinct semantics let the conflict surface; but the command itself
 * operates on either driver — its assertions are agnostic.
 *
 * For the duplicate-injection step we use the lower-level
 * `DB::statement(...)` to insert two rows directly. To avoid the unique
 * constraint on either driver we first DROP the index, write the rows,
 * then run the command — exactly the situation the operator finds
 * themselves in BEFORE the M3 milestone applies the index for the first
 * time.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Drop the unique index so we can manufacture the duplicate the
    // command is designed to detect. The migration's `down()` removes
    // this exact index, so this mirrors the pre-migration prod state.
    $driver = DB::connection()->getDriverName();
    if ($driver === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS documents_catalogue_identifier_unique');
    } else {
        // MySQL has no DROP INDEX IF EXISTS until 8.0.20; older versions
        // (and any state where the migration has not yet run) would throw.
        // Tolerate the "doesn't exist" case to keep the test idempotent
        // across re-runs and across driver versions.
        try {
            DB::statement('DROP INDEX documents_catalogue_identifier_unique ON documents');
        } catch (Throwable) {
            // index already absent — fine, the test simulates pre-migration state
        }
    }
});

function cdci_seedSeries(): Series
{
    return Series::create([
        'code' => 'S-' . substr(uniqid(), -6),
        'title' => 'Test series',
        'is_active' => true,
    ]);
}

function cdci_seedAuthority(): Authority
{
    return Authority::create([
        'identifier' => 'R-' . substr(uniqid(), -6),
        'surname' => 'Test',
        'entity_type' => 'PERSON',
    ]);
}

function cdci_seedDuplicate(string $value): void
{
    cdci_seedAuthority();
    $series = cdci_seedSeries();

    Document::factory()->count(2)->create([
        'catalogue_identifier' => $value,
        'series_id' => $series->id,
    ]);
}

/* -------------------------------------------------------------------------
 |  Test 1 — no duplicates → SUCCESS + "OK" message
 * ------------------------------------------------------------------------- */

test('preflight reports OK and exits 0 when no duplicates exist', function () {
    // Even a single row with a non-null catalogue_identifier is fine — the
    // command flags ONLY groups whose row_count > 1.
    Document::factory()->create([
        'catalogue_identifier' => 'CAT-1',
        'series_id' => cdci_seedSeries()->id,
    ]);

    $exit = Artisan::call('nra:check-duplicate-catalogue-identifier');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('OK')
        ->and($output)->toContain('UNIQUE migration is safe');
});

/* -------------------------------------------------------------------------
 |  Test 2 — duplicates → FAILURE + group printed
 * ------------------------------------------------------------------------- */

test('preflight reports duplicates and exits non-zero when there are conflicts', function () {
    cdci_seedDuplicate('CAT-DUP');

    $exit = Artisan::call('nra:check-duplicate-catalogue-identifier');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Found 1 duplicate catalogue_identifier group')
        ->and($output)->toContain('CAT-DUP');
});

/* -------------------------------------------------------------------------
 |  Test 3 — NULL catalogue_identifier is NEVER reported as a duplicate
 |  -----
 |  The migration's index is NULL-distinct, so multiple NULL rows are
 |  legal. The preflight must match that — otherwise it would flag every
 |  un-catalogued document on import day.
 * ------------------------------------------------------------------------- */

test('preflight ignores rows where catalogue_identifier is NULL', function () {
    Document::factory()->count(3)->create([
        'catalogue_identifier' => null,
        'series_id' => cdci_seedSeries()->id,
    ]);

    $exit = Artisan::call('nra:check-duplicate-catalogue-identifier');
    expect($exit)->toBe(0);
});
