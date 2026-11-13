<?php

declare(strict_types=1);

use App\Actions\Backup\RestoreDatabase;
use Illuminate\Support\Facades\File;

/*
 * Security audit 2026-11-13, lead 6c: the restore pipes the archive's SQL
 * dump into the `mysql` client, which also runs its own commands found in
 * the dump. A line `\! <command>` in an archive on a backup destination (an
 * admin-defined FTP/SFTP server, or one taken over) ran that command on the
 * application host. Reproduced against a throwaway MariaDB 12.3 server: the
 * file named by `\! touch` was created. The client now runs only with MariaDB's
 * --sandbox (which also blocks tee and source); otherwise the dump goes through PDO.
 */

function sa_restoreProbe(?string $sandboxOption): RestoreDatabase
{
    return new class($sandboxOption) extends RestoreDatabase
    {
        public bool $clientUsed = false;

        public function __construct(private readonly ?string $option) {}

        public function import(string $sqlPath): void
        {
            $this->importDump($sqlPath);
        }

        protected function mysqlClientAvailable(): bool
        {
            return true;
        }

        protected function clientSandboxOption(): ?string
        {
            return $this->option;
        }

        protected function importViaMysqlClient(string $sqlPath, array $config): void
        {
            $this->clientUsed = true;
        }
    };
}

it('trusts only MariaDB --sandbox, which also blocks tee and source', function () {
    expect(RestoreDatabase::sandboxOptionFromHelp('  --sandbox           Disallow commands that access the file system'))->toBe('--sandbox')
        // MySQL's --skip-system-command leaves tee (file write) and source working.
        ->and(RestoreDatabase::sandboxOptionFromHelp('  --system-command    Enable (by default) or disable the system mysql command.'))->toBeNull()
        ->and(RestoreDatabase::sandboxOptionFromHelp('                      expired password sandbox mode even if --batch was'))->toBeNull()
        ->and(RestoreDatabase::sandboxOptionFromHelp("  --batch             Don't use history file."))->toBeNull();
});

it('does not hand the dump to a mysql client that cannot be sandboxed', function () {
    config(['database.connections.' . config('database.default') . '.driver' => 'mysql']);
    $marker = sys_get_temp_dir() . '/bl-restore-probe-' . uniqid();
    $dump = tempnam(sys_get_temp_dir(), 'bl-dump-');
    file_put_contents($dump, "\\! touch {$marker}\n");

    $probe = sa_restoreProbe(null);

    try {
        expect(fn () => $probe->import($dump))->toThrow(Exception::class);
    } finally {
        File::delete($dump);
    }

    expect($probe->clientUsed)->toBeFalse()
        ->and(file_exists($marker))->toBeFalse();
});

it('uses the mysql client when it can run sandboxed', function () {
    config(['database.connections.' . config('database.default') . '.driver' => 'mysql']);
    $dump = tempnam(sys_get_temp_dir(), 'bl-dump-');
    file_put_contents($dump, "SELECT 1;\n");

    $probe = sa_restoreProbe('--sandbox');
    $probe->import($dump);
    File::delete($dump);

    expect($probe->clientUsed)->toBeTrue();
});
