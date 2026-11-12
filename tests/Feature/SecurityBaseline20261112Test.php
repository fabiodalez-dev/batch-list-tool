<?php

declare(strict_types=1);

use App\Actions\Backup\RestoreDatabase;
use App\Actions\Fortify\ResetUserPassword;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\BackupRun;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

/*
 * Security baseline §5 (password policy, idle timeout) and the backup folder
 * name, checked on 2026-11-12 before the production hand-over.
 */

function passwordPasses(string $password): bool
{
    return Validator::make(['p' => $password], ['p' => Password::default()])->passes();
}

test('the default password rule asks for 12 characters with upper case, lower case and a digit', function () {
    expect(passwordPasses('Short1Aa'))->toBeFalse()             // 8 characters
        ->and(passwordPasses('alllowercase12'))->toBeFalse()     // no upper case
        ->and(passwordPasses('ALLUPPERCASE12'))->toBeFalse()     // no lower case
        ->and(passwordPasses('NoDigitsAtAllHere'))->toBeFalse()  // no digit
        ->and(passwordPasses('Archive2026Malta'))->toBeTrue();
});

test('a password reset through Fortify follows the same policy', function () {
    $user = User::factory()->create();

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});

test('an administrator cannot give a new user a weak temporary password', function () {
    bl_seedShieldPermissions();
    $this->actingAs(bl_actor('super_admin', Repository::factory()->create()));

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Weak Password',
            'email' => 'weak@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'weak@example.test')->exists())->toBeFalse();
});

test('existing passwords are left alone: a user with an old short password still signs in', function () {
    $user = User::factory()->create(['password' => bcrypt('short')]);

    expect(auth()->attempt(['email' => $user->email, 'password' => 'short']))->toBeTrue();
});

test('an idle session ends after 30 minutes by default', function () {
    $saved = [$_ENV['SESSION_LIFETIME'] ?? null, $_SERVER['SESSION_LIFETIME'] ?? null, getenv('SESSION_LIFETIME')];
    putenv('SESSION_LIFETIME');
    unset($_ENV['SESSION_LIFETIME'], $_SERVER['SESSION_LIFETIME']);

    try {
        expect((require base_path('config/session.php'))['lifetime'])->toBe(30);
    } finally {
        if ($saved[0] !== null) {
            $_ENV['SESSION_LIFETIME'] = $saved[0];
        }
        if ($saved[1] !== null) {
            $_SERVER['SESSION_LIFETIME'] = $saved[1];
        }
        if ($saved[2] !== false) {
            putenv('SESSION_LIFETIME=' . $saved[2]);
        }
    }
});

test('the backup folder name can be pinned so renaming the app does not split the backups', function () {
    $_ENV['BACKUP_NAME'] = 'Logistics Tool';
    $_ENV['APP_NAME'] = 'Something Else';

    try {
        expect((require base_path('config/backup.php'))['backup']['name'])->toBe('Logistics Tool');
    } finally {
        unset($_ENV['BACKUP_NAME'], $_ENV['APP_NAME']);
    }
});

/*
 * Backups are now encrypted in production (BACKUP_ARCHIVE_PASSWORD). The
 * restore in the Backup Center has to open those archives, and stream the
 * dump instead of loading hundreds of MB into a web request.
 */
function encryptedBackupFixture(string $password, string $sql): string
{
    Storage::fake('local');
    $tmp = tempnam(sys_get_temp_dir(), 'bl-test-zip');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/mysql-test.sql', $sql);
    $zip->setEncryptionName('db-dumps/mysql-test.sql', ZipArchive::EM_AES_256, $password);
    $zip->close();
    Storage::disk('local')->put('Logistics Tool/backup.zip', file_get_contents($tmp));
    unlink($tmp);

    return 'Logistics Tool/backup.zip';
}

/** RestoreDatabase with the snapshot and the import replaced by a probe. */
function restoreProbe(): RestoreDatabase
{
    return new class extends RestoreDatabase
    {
        public ?string $imported = null;

        protected function safetySnapshot(): void {}

        protected function importDump(string $sqlPath): void
        {
            $this->imported = file_get_contents($sqlPath);
        }
    };
}

test('a restore opens an encrypted backup with the configured password', function () {
    config(['backup.backup.password' => 'Correct-Horse-2026']);
    $path = encryptedBackupFixture('Correct-Horse-2026', "CREATE TABLE t (id int);\n");

    $restore = restoreProbe();
    $run = $restore->restore('local', $path, null);

    expect($restore->imported)->toBe("CREATE TABLE t (id int);\n")
        ->and($run->status)->toBe('success');
});

test('a restore with the wrong backup password fails without importing anything', function () {
    config(['backup.backup.password' => 'Not-The-Right-One-1']);
    $path = encryptedBackupFixture('Correct-Horse-2026', "CREATE TABLE t (id int);\n");

    $restore = restoreProbe();

    expect(fn () => $restore->restore('local', $path, null))->toThrow(RuntimeException::class);
    expect($restore->imported)->toBeNull()
        ->and(BackupRun::latest('id')->first()?->status)->toBe('failed');
});
