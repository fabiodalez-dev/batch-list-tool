<?php

use App\Actions\Backup\RestoreDatabase;
use App\Filament\Pages\Settings\BackupHealthPage;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedRoles();
});

/** Create + authenticate a user holding the given role. */
function rg_login(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user);

    return $user;
}

// ─── 1. Restore action visibility — super_admin ONLY ────────────────────────

it('hides the restore action from admin and viewer but shows it to super_admin', function () {
    $page = new BackupHealthPage;

    rg_login('admin');
    expect($page->getRestoreAction()->isVisible())->toBeFalse();

    auth()->logout();
    rg_login('viewer');
    expect($page->getRestoreAction()->isVisible())->toBeFalse();

    auth()->logout();
    rg_login('super_admin');
    expect($page->getRestoreAction()->isVisible())->toBeTrue();
});

it('hides the restore action from a guest', function () {
    expect((new BackupHealthPage)->getRestoreAction()->isVisible())->toBeFalse();
});

// ─── 2. Typed-DB-name mismatch is rejected, no import attempted ──────────────

it('rejects a restore when the typed database name does not match', function () {
    rg_login('super_admin');

    // Spy: binding records if the restore service is ever resolved/invoked.
    $invoked = false;
    app()->bind(RestoreDatabase::class, function () use (&$invoked) {
        $invoked = true;

        return new RestoreDatabase;
    });

    $page = new BackupHealthPage;

    $call = fn () => $page->restoreFromBackup(
        ['disk' => 'local', 'path' => 'AppName/2026-05-30.zip'],
        ['understand' => true, 'confirm_database' => 'definitely-not-the-db'],
    );

    expect($call)->toThrow(ValidationException::class);
    expect($invoked)->toBeFalse();
    expect(BackupRun::where('type', 'restore')->count())->toBe(0);
});

it('accepts the typed name when it matches the current database name', function () {
    rg_login('super_admin');

    $expected = BackupHealthPage::currentDatabaseName();

    // Override the restore service so nothing real is imported.
    $captured = false;
    app()->bind(RestoreDatabase::class, function () use (&$captured) {
        $captured = true;

        return new class extends RestoreDatabase
        {
            public function restore(string $disk, string $zipPath, ?int $userId): BackupRun
            {
                return BackupRun::create([
                    'type' => 'restore',
                    'status' => 'success',
                    'started_at' => now(),
                    'message' => 'fake restore',
                ]);
            }
        };
    });

    $page = new BackupHealthPage;
    $page->restoreFromBackup(
        ['disk' => 'local', 'path' => 'AppName/2026-05-30.zip'],
        ['understand' => true, 'confirm_database' => $expected],
    );

    expect($captured)->toBeTrue();
    expect(BackupRun::where('type', 'restore')->where('status', 'success')->exists())->toBeTrue();
});

// ─── 3. super_admin-only server-side (defence in depth) ─────────────────────

it('aborts 403 server-side when a non super_admin invokes the handler', function () {
    rg_login('admin');

    $page = new BackupHealthPage;

    $call = fn () => $page->restoreFromBackup(
        ['disk' => 'local', 'path' => 'AppName/2026-05-30.zip'],
        ['understand' => true, 'confirm_database' => BackupHealthPage::currentDatabaseName()],
    );

    expect($call)->toThrow(HttpException::class);

    try {
        $call();
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    expect(BackupRun::where('type', 'restore')->count())->toBe(0);
});

// ─── 4. Safety-snapshot-first ordering ──────────────────────────────────────

it('takes the safety snapshot before attempting any import', function () {
    SnapshotOrderProbe::$log = [];

    // Stub Artisan::call so backup:run does nothing real but reports success (0),
    // recording the command name into the shared ordering probe.
    Artisan::shouldReceive('call')
        ->andReturnUsing(function (string $command, array $params = []) {
            SnapshotOrderProbe::$log[] = $command;

            return 0;
        });

    // Subclass whose import step records into the same log so we can assert the
    // snapshot command ran first.
    $service = new class extends RestoreDatabase
    {
        protected function extractSqlDump(string $zipPath): string
        {
            return tempnam(sys_get_temp_dir(), 'probe') . '.sql';
        }

        protected function importDump(string $sqlPath): void
        {
            SnapshotOrderProbe::$log[] = 'IMPORT';
        }
    };

    $run = $service->restore('local', '/tmp/fake.zip', null);

    expect(SnapshotOrderProbe::$log)->toBe(['backup:run', 'IMPORT']);
    expect($run->type)->toBe('restore');
    expect($run->status)->toBe('success');
});

it('never reaches the import when the safety snapshot throws', function () {
    $service = new class extends RestoreDatabase
    {
        public bool $importReached = false;

        protected function safetySnapshot(): void
        {
            throw new RuntimeException('snapshot blew up');
        }

        protected function extractSqlDump(string $zipPath): string
        {
            $this->importReached = true;

            return 'never';
        }

        protected function importDump(string $sqlPath): void
        {
            $this->importReached = true;
        }
    };

    expect(fn () => $service->restore('local', '/tmp/fake.zip', null))
        ->toThrow(RuntimeException::class);

    expect($service->importReached)->toBeFalse();
    // Aborted before the try/catch import block, so no restore row is written.
    expect(BackupRun::where('type', 'restore')->count())->toBe(0);
});

it('aborts and records no restore when the snapshot command exits non-zero', function () {
    Artisan::shouldReceive('call')->andReturn(1); // non-zero exit

    $service = new class extends RestoreDatabase
    {
        public bool $importReached = false;

        protected function extractSqlDump(string $zipPath): string
        {
            $this->importReached = true;

            return 'never';
        }

        protected function importDump(string $sqlPath): void
        {
            $this->importReached = true;
        }
    };

    expect(fn () => $service->restore('local', '/tmp/fake.zip', null))
        ->toThrow(RuntimeException::class);

    expect($service->importReached)->toBeFalse();
    expect(BackupRun::where('type', 'restore')->count())->toBe(0);
});

/**
 * Shared ordering log for the snapshot-first test.
 */
class SnapshotOrderProbe
{
    /** @var array<int, string> */
    public static array $log = [];
}
