<?php

use App\Models\BackupDestination;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// BackupDestination — encrypted config at rest
// ---------------------------------------------------------------------------

test('BackupDestination encrypts config at rest and decrypts on read', function () {
    $dest = BackupDestination::create([
        'name' => 'FTP Server',
        'driver' => 'ftp',
        'disk_key' => 'ftp-backup',
        'config' => ['host' => 'ftp.example.com', 'password' => 'secret'],
    ]);

    // Reading back via Eloquent should work (decryption transparent).
    $fresh = BackupDestination::find($dest->id);
    expect($fresh->config['host'])->toBe('ftp.example.com');

    // The raw DB value must be ciphertext, NOT the plaintext credential.
    $rawConfig = DB::table('backup_destinations')->where('id', $dest->id)->value('config');
    expect($rawConfig)->not->toContain('ftp.example.com');
    expect($rawConfig)->not->toContain('secret');
});

// ---------------------------------------------------------------------------
// BackupDestination — scopeActive
// ---------------------------------------------------------------------------

test('BackupDestination::active() excludes inactive and orders by sort_order', function () {
    BackupDestination::create([
        'name' => 'Inactive',
        'driver' => 'ftp',
        'disk_key' => 'ftp-inactive',
        'config' => [],
        'is_active' => false,
        'sort_order' => 0,
    ]);

    BackupDestination::create([
        'name' => 'Second',
        'driver' => 's3',
        'disk_key' => 's3-secondary',
        'config' => [],
        'is_active' => true,
        'sort_order' => 2,
    ]);

    BackupDestination::create([
        'name' => 'First',
        'driver' => 's3',
        'disk_key' => 's3-primary',
        'config' => [],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $active = BackupDestination::active()->get();

    expect($active)->toHaveCount(2);
    expect($active->first()->name)->toBe('First');
    expect($active->last()->name)->toBe('Second');
});

// ---------------------------------------------------------------------------
// BackupDestination — diskName() helper
// ---------------------------------------------------------------------------

test('BackupDestination::diskName() returns disk_key', function () {
    $dest = BackupDestination::create([
        'name' => 'Local',
        'driver' => 'local',
        'disk_key' => 'local-backups',
        'config' => [],
    ]);

    expect($dest->diskName())->toBe('local-backups');
});

// ---------------------------------------------------------------------------
// BackupRun — datetime casts
// ---------------------------------------------------------------------------

test('BackupRun datetime columns are cast correctly', function () {
    $run = BackupRun::create([
        'type' => 'full',
        'status' => 'completed',
        'started_at' => '2026-05-31 08:00:00',
        'finished_at' => '2026-05-31 08:05:30',
        'duration_seconds' => 330,
        'size_bytes' => 1024 * 1024 * 50,
    ]);

    $fresh = BackupRun::find($run->id);

    expect($fresh->started_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->finished_at)->toBeInstanceOf(Carbon::class);
    expect($fresh->duration_seconds)->toBe(330);
    expect($fresh->size_bytes)->toBe(1024 * 1024 * 50);
});

// ---------------------------------------------------------------------------
// BackupRun — scopeRecent
// ---------------------------------------------------------------------------

test('BackupRun::recent() orders by started_at descending', function () {
    BackupRun::create([
        'type' => 'full',
        'status' => 'completed',
        'started_at' => '2026-05-29 06:00:00',
    ]);

    BackupRun::create([
        'type' => 'incremental',
        'status' => 'completed',
        'started_at' => '2026-05-31 06:00:00',
    ]);

    BackupRun::create([
        'type' => 'full',
        'status' => 'running',
        'started_at' => '2026-05-30 06:00:00',
    ]);

    $runs = BackupRun::recent()->get();

    expect($runs->first()->started_at->toDateString())->toBe('2026-05-31');
    expect($runs->last()->started_at->toDateString())->toBe('2026-05-29');
});
