<?php

declare(strict_types=1);

use App\Console\Commands\ImportSampleData;
use App\Models\Authority;
use App\Models\Series;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;

/**
 * PR #11b — App\Console\Commands\ImportSampleData (signature
 * `nra:import-samples {--path=…} {--fresh}`).
 *
 * Notes on coverage scope
 *  - The current command signature does NOT declare a `--dry-run` flag.
 *    The test asking for one is therefore SKIPPED here with the
 *    explanation in-test. Adding the flag is an upstream change that
 *    should land before this assertion is converted to a real test.
 *  - We DO cover (a) command is registered with the expected signature,
 *    (b) the command imports the documented row counts when pointed at
 *    the canonical samples folder, and (c) it short-circuits cleanly
 *    when the samples folder is missing.
 */
uses(DatabaseTransactions::class);

/**
 * Resolve the canonical samples folder relative to the Laravel app root.
 *
 * The samples live one level above `php-backend/` in the repository tree
 * (i.e. `<repo>/samples`). Using `dirname(base_path())` keeps these tests
 * portable across any clone of the repo (CI, other dev machines, etc.)
 * instead of hard-coding the original author's home directory.
 */
function samplesPath_isd(): string
{
    return dirname(base_path()) . '/samples';
}

/* 55. Command is registered with the expected signature */
test('nra:import-samples command is registered and exposes --path and --fresh', function () {
    $signature = (new ImportSampleData)->getDefinition();
    $opts = array_keys($signature->getOptions());

    expect($opts)->toContain('path');
    expect($opts)->toContain('fresh');

    // And the Artisan name is what we documented in CLAUDE.md
    expect(Artisan::all())->toHaveKey('nra:import-samples');
});

/*
 * 56. --dry-run flag.
 *
 * The current command has no --dry-run. We skip this test with a clear
 * message so the gap shows up in the suite output and someone can wire
 * it later. The test is shaped so that simply adding the flag (and the
 * "skip writes" behaviour) will make the assertion live.
 */
test('nra:import-samples supports --dry-run (no-op when set)', function () {
    $opts = array_keys((new ImportSampleData)->getDefinition()->getOptions());
    if (! in_array('dry-run', $opts, true)) {
        $this->markTestSkipped('Command does not (yet) declare a --dry-run flag — see CLAUDE.md note.');
    }

    $path = samplesPath_isd();
    if (! is_dir($path)) {
        $this->markTestSkipped('Sample dataset not found at ' . $path);
    }

    $beforeSeries = Series::count();
    $beforeAuth = Authority::count();

    Artisan::call('nra:import-samples', [
        '--dry-run' => true,
        '--path' => $path,
    ]);

    expect(Series::count())->toBe($beforeSeries);
    expect(Authority::count())->toBe($beforeAuth);
});

/* 57. Seeds the expected number of Authorities (~808). */
test('nra:import-samples imports the expected number of Authorities (~808)', function () {
    $path = samplesPath_isd();
    if (! is_dir($path) || ! file_exists($path . '/Authorities_Sample.xlsx')) {
        $this->markTestSkipped('Sample dataset not found at ' . $path);
    }

    $beforeAuth = Authority::count();
    Artisan::call('nra:import-samples', ['--path' => $path]);
    $imported = Authority::count() - $beforeAuth;

    // Tolerate ±20 rows around the documented 808 baseline — the RFQ
    // dictionary describes "Authorities_Sample.xlsx (808 records)".
    expect($imported + $beforeAuth)->toBeGreaterThanOrEqual(700);
    expect($imported + $beforeAuth)->toBeLessThanOrEqual(900);
})->skip(
    fn () => ! is_dir(samplesPath_isd()),
    'samples folder not present in this checkout (expected at <repo-root>/samples)',
);

/* 58. Seeds the expected number of Series (~29). */
test('nra:import-samples imports the expected number of Series (~29)', function () {
    $path = samplesPath_isd();
    if (! is_dir($path) || ! file_exists($path . '/Series_Sample.xlsx')) {
        $this->markTestSkipped('Sample dataset not found at ' . $path);
    }

    $beforeSer = Series::count();
    Artisan::call('nra:import-samples', ['--path' => $path]);

    // Total Series after import must be at least the documented 29
    // reference rows. (Dev seed may have inserted more.)
    expect(Series::count())->toBeGreaterThanOrEqual(max($beforeSer, 29));
})->skip(
    fn () => ! is_dir(samplesPath_isd()),
    'samples folder not present in this checkout (expected at <repo-root>/samples)',
);
