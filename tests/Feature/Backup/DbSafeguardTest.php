<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

// TestCase is bound to tests/Feature via tests/Pest.php, but RefreshDatabase is
// not (only the Browser suite binds it globally), so opt in here per the task.
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Pre-migrate safety-backup listener
|--------------------------------------------------------------------------
*/

it('does not dispatch backup:run when migrations start on the sqlite test connection', function () {
    // The test suite runs on sqlite (:memory:). Firing the real spatie event
    // must NOT trigger a backup — if it tried, spatie would attempt a
    // mysqldump and blow up on sqlite. Reaching the assertion without an
    // exception proves the listener short-circuited for sqlite + running tests.
    expect(config('database.default'))->toBe('sqlite');

    // MigrationsStarted carries the invoked method ('up') + options.
    Event::dispatch(new MigrationsStarted('up', []));

    expect(true)->toBeTrue();
});

it('decision method returns false for sqlite, for tests, and for the skip flag', function () {
    // sqlite driver → never back up
    expect(AppServiceProvider::shouldRunPreMigrateBackup('sqlite', false, false))->toBeFalse();

    // running the test suite → never back up (even on a real driver)
    expect(AppServiceProvider::shouldRunPreMigrateBackup('mysql', true, false))->toBeFalse();

    // explicit BACKUP_SKIP_PRE_MIGRATE escape hatch → never back up
    expect(AppServiceProvider::shouldRunPreMigrateBackup('mysql', false, true))->toBeFalse();

    // null driver (no connection resolved) → never back up
    expect(AppServiceProvider::shouldRunPreMigrateBackup(null, false, false))->toBeFalse();
});

it('decision method returns true for a real non-sqlite driver outside tests', function () {
    expect(AppServiceProvider::shouldRunPreMigrateBackup('mysql', false, false))->toBeTrue();
    expect(AppServiceProvider::shouldRunPreMigrateBackup('mariadb', false, false))->toBeTrue();
    expect(AppServiceProvider::shouldRunPreMigrateBackup('pgsql', false, false))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| db:refresh-safe command
|--------------------------------------------------------------------------
*/

it('aborts db:refresh-safe on the production environment', function () {
    // Force the framework to report the production environment. Application::
    // environment() reads the container's bound 'env' string, so setting it
    // here makes $this->getLaravel()->environment('production') return true
    // without touching the DB. The command must bail out (FAILURE) BEFORE any
    // migrate:fresh runs.
    app()->instance('env', 'production');

    try {
        // --force so the only thing that can stop it is the production guard.
        $exit = Artisan::call('db:refresh-safe', ['--force' => true]);

        // 1 === Symfony\Component\Console\Command\Command::FAILURE
        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('refused on the production environment');
    } finally {
        // Always restore the testing environment, even if an assertion above
        // fails, so a failure here does not leak 'production' into later tests.
        app()->instance('env', 'testing');
    }
});

it('registers db:refresh-safe with the expected signature options', function () {
    $command = Artisan::all()['db:refresh-safe'] ?? null;

    expect($command)->not->toBeNull();

    $definition = $command->getDefinition();

    expect($definition->hasOption('seed'))->toBeTrue()
        ->and($definition->hasOption('samples'))->toBeTrue()
        ->and($definition->hasOption('force'))->toBeTrue();
});
