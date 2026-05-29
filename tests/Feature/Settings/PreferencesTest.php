<?php

declare(strict_types=1);

use App\Filament\Pages\Account\PreferencesPage;
use App\Http\Middleware\ApplyUserPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * My account › Preferences page.
 *
 * Covers:
 *   - user can persist page size, locale, and timezone
 *   - middleware applies the locale for the current request
 *   - preferred_page_size column persists (table default logic tested via column)
 *   - canAccess() returns true for any authenticated user
 *
 * NOTE: The default_repository_id field was MOVED to the Profile page (EditProfile).
 * All repo-related assertions for the Preferences page have been removed; they
 * live in tests/Feature/Settings/ProfileDefaultRepositoryTest.php.
 */
uses(RefreshDatabase::class);

// ─── helpers ────────────────────────────────────────────────────────────────

function makeActiveUser(): User
{
    return User::factory()->create([
        'email' => 'pref-user+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('canAccess returns true for any authenticated user', function () {
    $u = makeActiveUser();
    $this->actingAs($u);

    expect(PreferencesPage::canAccess())->toBeTrue();
});

it('persists page size, locale and timezone', function () {
    $u = makeActiveUser();
    $this->actingAs($u);

    Livewire\Livewire::test(PreferencesPage::class)
        ->fillForm([
            'preferred_page_size' => 50,
            'locale' => 'it',
            'timezone' => 'Europe/Malta',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $u->fresh();
    expect($fresh->preferred_page_size)->toBe(50);
    expect($fresh->locale)->toBe('it');
    expect($fresh->timezone)->toBe('Europe/Malta');
});

it('pre-fills the form with the current preferences', function () {
    $u = makeActiveUser();
    $u->update([
        'preferred_page_size' => 100,
        'locale' => 'en',
        'timezone' => 'UTC',
    ]);
    $this->actingAs($u);

    Livewire\Livewire::test(PreferencesPage::class)
        ->assertFormSet([
            'preferred_page_size' => 100,
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
});

it('applies the user locale via middleware', function () {
    bl_seedRoles();

    $u = User::factory()->create([
        'email' => 'locale-test+' . uniqid() . '@test.local',
        'is_active' => true,
        'locale' => 'it',
    ]);
    $u->assignRole('editor');

    $response = $this->actingAs($u)->get('/admin');
    expect($response->status())->toBeLessThan(500);

    // Verify middleware logic directly: simulate calling the middleware
    // with the authenticated user having locale 'it'.
    Auth::setUser($u);

    app()->setLocale('en'); // reset to a known value

    $middleware = new ApplyUserPreferences;
    $middleware->handle(
        Request::create('/admin'),
        function ($req) {
            return new Response;
        },
    );

    expect(app()->getLocale())->toBe('it');
});

it('preferred_page_size column persists and can be read back', function () {
    $u = makeActiveUser();
    $u->update(['preferred_page_size' => 50]);

    expect((int) $u->fresh()->preferred_page_size)->toBe(50);
});
