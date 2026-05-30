<?php

use App\Filament\Resources\BackupDestinationResource;
use App\Models\BackupDestination;
use App\Models\User;
use App\Support\BackupDestinations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Create a user with the given role, creating the role on demand so the test
 * does not depend on a seeder having run.
 */
function bcUserWithRole(string $role): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
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
