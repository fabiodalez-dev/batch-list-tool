<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * 2026-11-12: the import-finished notification (with "Download information
 * about the failed rows") was written to the notifications table but never
 * shown: the panel had no database notifications. The wizard also told the
 * user to watch a tray that did not exist.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

it('shows database notifications in the admin panel, polled', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasDatabaseNotifications())->toBeTrue()
        ->and($panel->getDatabaseNotificationsPollingInterval())->toBe('30s');

    // The bell is on the page (it loads lazily, so its placeholder holds the trigger).
    $this->withoutVite()->get('/admin')->assertOk()->assertSee('fi-topbar-database-notifications-btn', escape: false);
});

it('delivers the import-finished notification, with the failed rows link, to the user who started the import', function (): void {
    $other = User::factory()->create(['is_active' => true]);

    // One good row and one with a Subseries that does not exist.
    $csv = "Subseries,Volume\nREG,1\nNOPE-SUBSERIES,2\n";

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.csv', $csv))
        ->set('data.confirm', true)
        ->call('startImport')
        ->assertNotified('Import started');

    $notification = $this->user->fresh()->notifications()->sole();
    $data = $notification->data;

    expect($data['title'])->toContain('completed')
        ->and(collect($data['actions'] ?? [])->pluck('name'))->toContain('downloadFailedRowsCsv')
        ->and($other->fresh()->notifications()->count())->toBe(0);

    // The bell's Livewire component lists it, unread, for that user.
    // A new "request" for the bell: the panel and the signed-in user again.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user->fresh());
    $bell = new DatabaseNotifications;
    expect($bell->getUnreadNotificationsCount())->toBe(1)
        ->and($bell->getNotifications()->first()?->getKey())->toBe($notification->getKey());
});

it('tells the user where the result will appear', function (): void {
    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.csv', "Subseries,Volume\nREG,1\n"))
        ->set('data.confirm', true)
        ->call('startImport');

    // Read the flashed notifications once (assertNotified would consume them).
    $flashed = new Notifications;
    $flashed->mount();
    $started = $flashed->notifications->first(fn (Notification $n): bool => $n->getTitle() === 'Import started');

    expect($started)->not->toBeNull()
        ->and((string) $started->getBody())->toContain('bell icon')
        ->not->toContain('notifications tray for progress');
});
