<?php

namespace Tests\Feature\Backup;

use App\Models\BackupDestination;
use App\Support\BackupDestinations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use League\Flysystem\Ftp\FtpAdapter;
use Tests\TestCase;

class BackupDestinationsRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $testRoot;

    public function test_active_local_destination_is_registered_as_disk_and_backup_target(): void
    {
        BackupDestination::create([
            'name' => 'Local test',
            'driver' => 'local',
            'disk_key' => 'bc_local_test',
            'config' => ['root' => $this->testRoot],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        BackupDestinations::register();

        // The filesystem disk is wired into runtime config.
        $disk = config('filesystems.disks.bc_local_test');
        $this->assertIsArray($disk);
        $this->assertSame('local', $disk['driver']);
        $this->assertSame($this->testRoot, $disk['root']);

        // The disk key is appended to the spatie/laravel-backup disk list,
        // and 'local' is always preserved.
        $disks = config('backup.backup.destination.disks');
        $this->assertContains('bc_local_test', $disks);
        $this->assertContains('local', $disks);
    }

    public function test_test_connection_succeeds_for_local_destination(): void
    {
        $destination = BackupDestination::create([
            'name' => 'Local test',
            'driver' => 'local',
            'disk_key' => 'bc_local_test',
            'config' => ['root' => $this->testRoot],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        $result = BackupDestinations::testConnection($destination);

        $this->assertTrue($result['ok'], 'Expected local test-connection to succeed. Got: ' . $result['message']);
        $this->assertSame('Connection OK', $result['message']);

        // The probe file must have been cleaned up.
        $this->assertFileDoesNotExist($this->testRoot . '/.bc-conn-test');
    }

    public function test_inactive_destination_is_not_registered(): void
    {
        BackupDestination::create([
            'name' => 'Inactive',
            'driver' => 'local',
            'disk_key' => 'bc_inactive_test',
            'config' => ['root' => $this->testRoot],
            'is_active' => false,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        BackupDestinations::register();

        $this->assertNull(config('filesystems.disks.bc_inactive_test'));
        $this->assertNotContains('bc_inactive_test', config('backup.backup.destination.disks'));
    }

    public function test_test_connection_fails_gracefully_for_unreachable_ftp(): void
    {
        // The FTP flysystem adapter is an optional dependency. Only assert the
        // failure path when it is actually installed; otherwise skip with a note.
        if (! class_exists(FtpAdapter::class)) {
            $this->markTestSkipped('league/flysystem-ftp adapter not installed — skipping FTP connectivity assertion.');
        }

        $destination = BackupDestination::create([
            'name' => 'Bad FTP',
            'driver' => 'ftp',
            'disk_key' => 'bc_ftp_test',
            'config' => [
                'host' => 'nonexistent.invalid',
                'username' => 'user',
                'password' => 'secret',
                'port' => 21,
                'root' => '',
            ],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        $result = BackupDestinations::testConnection($destination);

        $this->assertFalse($result['ok']);
        $this->assertIsString($result['message']);
        $this->assertNotSame('', $result['message']);

        // The credentials must never leak into the returned message.
        $this->assertStringNotContainsString('secret', $result['message']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRoot = storage_path('app/bc-test');
    }

    protected function tearDown(): void
    {
        // Clean up any directory created by a local-disk write during the test.
        if (is_dir($this->testRoot)) {
            File::deleteDirectory($this->testRoot);
        }

        parent::tearDown();
    }
}
