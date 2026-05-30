<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BackupHealthPage;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

// Pest.php only binds RefreshDatabase to the Browser suite, so opt in here.
uses(RefreshDatabase::class);

// Seed the four roles + Shield permissions so the Filament panel boots and
// resource/page discovery succeeds (mirrors the existing BackupHealthTest).
beforeEach(function () {
    bl_seedShieldPermissions();
});

/**
 * Resolve a user with the given role (roles are seeded in beforeEach).
 */
function backupUserWithRole(string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Seed a fake .zip backup on the first configured destination disk, inside the
 * configured backup-name directory, and return [disk, path].
 *
 * @return array{0: string, 1: string}
 */
function fakeBackupZip(): array
{
    /** @var array<int, string> $disks */
    $disks = config('backup.backup.destination.disks', ['local']);
    $disk = $disks[0] ?? 'local';

    Storage::fake($disk);

    $appName = (string) config('backup.backup.name', config('app.name', 'laravel-backup'));
    $path = trim($appName, '/') . '/2026-05-30-12-00-00.zip';

    Storage::disk($disk)->put($path, 'PK-fake-zip-bytes');

    return [$disk, $path];
}

// ─── access control ──────────────────────────────────────────────────────────

it('renders the Backup Center for an admin', function () {
    $this->actingAs(backupUserWithRole('admin'));

    livewire(BackupHealthPage::class)->assertSuccessful();
});

it('renders the Backup Center for a super_admin', function () {
    $this->actingAs(backupUserWithRole('super_admin'));

    livewire(BackupHealthPage::class)->assertSuccessful();
});

it('denies the Backup Center to a viewer', function () {
    $this->actingAs(backupUserWithRole('viewer'));

    livewire(BackupHealthPage::class)->assertForbidden();
});

// ─── listBackups() ───────────────────────────────────────────────────────────

it('lists existing zip backups from the destination disk', function () {
    [$disk, $path] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    $page = new BackupHealthPage;
    $backups = $page->listBackups();

    expect($backups)->toHaveCount(1);
    expect($backups[0]['disk'])->toBe($disk);
    expect($backups[0]['path'])->toBe($path);
    expect($backups[0]['filename'])->toBe('2026-05-30-12-00-00.zip');
});

// ─── download route ──────────────────────────────────────────────────────────

it('denies the download route to a viewer', function () {
    [$disk, $path] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('viewer'));

    $this->get(route('backups.download', ['disk' => $disk, 'path' => $path]))
        ->assertForbidden();
});

it('lets an admin stream a backup download', function () {
    [$disk, $path] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    $this->get(route('backups.download', ['disk' => $disk, 'path' => $path]))
        ->assertOk();
});

it('rejects a non-zip download path', function () {
    [$disk] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    $this->get(route('backups.download', ['disk' => $disk, 'path' => 'laravel-backup/secrets.env']))
        ->assertForbidden();
});

it('rejects a directory-traversal download path', function () {
    [$disk] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    $this->get(route('backups.download', ['disk' => $disk, 'path' => '../../.env.zip']))
        ->assertForbidden();
});

it('rejects an unknown disk for download', function () {
    fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    $this->get(route('backups.download', ['disk' => 'not-a-backup-disk', 'path' => 'laravel-backup/x.zip']))
        ->assertForbidden();
});

// ─── on-demand run actions ───────────────────────────────────────────────────

it('queues a db-only backup and records a running BackupRun', function () {
    Queue::fake();

    $user = backupUserWithRole('admin');
    $this->actingAs($user);

    livewire(BackupHealthPage::class)
        ->callAction('runDbBackup')
        ->assertHasNoActionErrors();

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('running');
    expect($run->type)->toBe('db');
    expect($run->triggered_by_user_id)->toBe($user->id);
});

// ─── delete action ───────────────────────────────────────────────────────────

it('deletes a backup archive via the Livewire action', function () {
    [$disk, $path] = fakeBackupZip();

    $this->actingAs(backupUserWithRole('admin'));

    expect(Storage::disk($disk)->exists($path))->toBeTrue();

    livewire(BackupHealthPage::class)
        ->call('deleteBackup', $disk, $path);

    expect(Storage::disk($disk)->exists($path))->toBeFalse();
});
