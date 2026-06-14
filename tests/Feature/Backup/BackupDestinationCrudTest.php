<?php

use App\Filament\Resources\BackupDestinationResource;
use App\Filament\Resources\BackupDestinationResource\Pages\CreateBackupDestination;
use App\Models\BackupDestination;
use App\Models\User;
use App\Support\BackupDestinations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Create a user with the given role, creating the role on demand so the test
 * does not depend on a seeder having run.
 */
function bcUserWithRole(string $role): User
{
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate($role, 'web');

    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('grants access to admin and super_admin and denies viewer', function () {
    test()->actingAs(bcUserWithRole('admin'));
    expect(BackupDestinationResource::canAccess())->toBeTrue();
    expect(BackupDestinationResource::canViewAny())->toBeTrue();

    test()->actingAs(bcUserWithRole('super_admin'));
    expect(BackupDestinationResource::canAccess())->toBeTrue();

    test()->actingAs(bcUserWithRole('viewer'));
    expect(BackupDestinationResource::canAccess())->toBeFalse();
    expect(BackupDestinationResource::canViewAny())->toBeFalse();
    expect(BackupDestinationResource::shouldRegisterNavigation())->toBeFalse();
});

it('stores FTP credentials encrypted at rest and decrypts on read', function () {
    $destination = BackupDestination::create([
        'name' => 'Offsite FTP',
        'driver' => 'ftp',
        'disk_key' => 'offsite_ftp',
        'config' => [
            'host' => 'ftp.example.test',
            'username' => 'naf-backup',
            'password' => 'S3cr3t-Plain-Pass',
        ],
        'is_active' => true,
    ]);

    // Raw DB value must be ciphertext: no plaintext secrets present.
    $raw = (string) DB::table('backup_destinations')
        ->where('id', $destination->getKey())
        ->value('config');

    expect($raw)->not->toContain('S3cr3t-Plain-Pass');
    expect($raw)->not->toContain('ftp.example.test');
    expect($raw)->not->toContain('naf-backup');

    // Reading back through the model decrypts.
    $fresh = $destination->fresh();
    expect($fresh->config['host'])->toBe('ftp.example.test');
    expect($fresh->config['username'])->toBe('naf-backup');
    expect($fresh->config['password'])->toBe('S3cr3t-Plain-Pass');
});

it('exposes a runtime ftp disk after register()', function () {
    BackupDestination::create([
        'name' => 'Offsite FTP',
        'driver' => 'ftp',
        'disk_key' => 'offsite_ftp',
        'config' => [
            'host' => 'ftp.example.test',
            'username' => 'naf-backup',
            'password' => 'S3cr3t-Plain-Pass',
            'port' => 21,
        ],
        'is_active' => true,
    ]);

    BackupDestinations::register();

    $disk = config('filesystems.disks.offsite_ftp');
    expect($disk)->toBeArray();
    expect($disk['driver'])->toBe('ftp');
    expect($disk['host'])->toBe('ftp.example.test');
});

it('preserves the stored password when other fields are updated without re-entering it', function () {
    $destination = BackupDestination::create([
        'name' => 'Offsite FTP',
        'driver' => 'ftp',
        'disk_key' => 'offsite_ftp',
        'config' => [
            'host' => 'ftp.example.test',
            'username' => 'naf-backup',
            'password' => 'S3cr3t-Plain-Pass',
        ],
        'is_active' => true,
    ]);

    // Simulate the write-only secret flow: edit form strips the secret on fill
    // and re-merges it on save, so updating "name only" keeps the password.
    $config = $destination->config;
    $config['host'] = 'ftp.changed.test'; // a non-secret change
    $destination->update([
        'name' => 'Renamed FTP',
        'config' => $config,
    ]);

    $fresh = $destination->fresh();
    expect($fresh->name)->toBe('Renamed FTP');
    expect($fresh->config['host'])->toBe('ftp.changed.test');
    expect($fresh->config['password'])->toBe('S3cr3t-Plain-Pass');
});

it('auto-generates a unique disk_key from the name when left blank', function () {
    // First destination: disk_key derived from the name.
    $a = BackupDestination::create([
        'name' => 'Off-site FTP (Aruba)',
        'driver' => 'ftp',
        'disk_key' => BackupDestinationResource::uniqueDiskKey('Off-site FTP (Aruba)'),
        'config' => ['host' => 'ftp.example.com'],
    ]);

    expect($a->disk_key)->toBe('off_site_ftp_aruba');

    // Second destination with the SAME name gets a suffixed, non-colliding key.
    $second = BackupDestinationResource::uniqueDiskKey('Off-site FTP (Aruba)');
    expect($second)->toBe('off_site_ftp_aruba_2');

    // A blank/symbol-only name still yields a usable key.
    expect(BackupDestinationResource::uniqueDiskKey('   '))->toBe('backup');
});

it('fills disk_key on create when the form leaves it blank', function () {
    test()->actingAs(bcUserWithRole('admin'));

    Livewire::test(CreateBackupDestination::class)
        ->fillForm([
            'name' => 'Nightly SFTP',
            'driver' => 'sftp',
            'disk_key' => null,
            'config' => ['host' => 'sftp.example.com', 'username' => 'u'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BackupDestination::where('name', 'Nightly SFTP')->first()?->disk_key)->toBe('nightly_sftp');
});
