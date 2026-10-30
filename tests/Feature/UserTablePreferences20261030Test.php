<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\FlagsRelationManager;
use App\Filament\Resources\PracticeResource\Pages\ListPractices;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Models\UserTablePreference;
use App\Support\UserTablePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Client 2026-10-30, issue 8: "Is it possible to keep the order for the
 * particular user after logging out. So the order is associated with the
 * user?" Column order and hidden columns used to live in the session only.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/** A new session for $user: what a logout and a fresh login leave behind. */
function utp_freshLogin(User $user): void
{
    Auth::logout();
    session()->flush();
    Auth::login($user);
}

/** @return list<string> */
function utp_order(Testable $page): array
{
    return collect($page->instance()->tableColumns)->pluck('name')->values()->all();
}

/** @return list<string> */
function utp_defaultOrder(Testable $page): array
{
    return collect($page->instance()->getDefaultTableColumnState())->pluck('name')->values()->all();
}

/**
 * Drag the last column to the front, as the column manager does.
 *
 * @return list<string>
 */
function utp_moveLastToFront(Testable $page): array
{
    $state = $page->instance()->tableColumns;
    array_unshift($state, array_pop($state));
    $page->call('applyTableColumnManager', $state, true);

    return collect($state)->pluck('name')->values()->all();
}

it('saves a reordered layout for the user and restores it after logging in again', function (): void {
    $user = bl_actor('super_admin');
    utp_freshLogin($user);

    $page = Livewire::test(ListPractices::class);
    $default = utp_defaultOrder($page);
    $reordered = utp_moveLastToFront($page);

    expect($reordered)->not->toBe($default)
        ->and(UserTablePreference::query()->where('user_id', $user->id)->count())->toBe(1);

    utp_freshLogin($user);

    expect(utp_order(Livewire::test(ListPractices::class)))->toBe($reordered);
});

it('saves a hidden column and keeps it hidden in a new session', function (): void {
    $user = bl_actor('super_admin');
    utp_freshLogin($user);

    $page = Livewire::test(ListPractices::class);
    expect($page->instance()->isTableColumnToggledHidden('description'))->toBeFalse();

    $state = collect($page->instance()->tableColumns)
        ->map(fn (array $c): array => $c['name'] === 'description' ? [...$c, 'isToggled' => false] : $c)
        ->all();
    $page->call('applyTableColumnManager', $state);

    utp_freshLogin($user);

    $page = Livewire::test(ListPractices::class);
    expect($page->instance()->isTableColumnToggledHidden('description'))->toBeTrue()
        ->and($page->instance()->isTableColumnToggledHidden('name'))->toBeFalse();
});

it('keeps one user\'s layout away from another user', function (): void {
    $alice = bl_actor('super_admin');
    $bob = bl_actor('super_admin');

    utp_freshLogin($alice);
    $page = Livewire::test(ListPractices::class);
    $default = utp_defaultOrder($page);
    utp_moveLastToFront($page);

    utp_freshLogin($bob);
    expect(utp_order(Livewire::test(ListPractices::class)))->toBe($default)
        ->and(UserTablePreference::query()->where('user_id', $bob->id)->exists())->toBeFalse();

    // Switching user inside one session (impersonation) does not carry it over either.
    utp_freshLogin($alice);
    utp_moveLastToFront(Livewire::test(ListPractices::class));
    Auth::login($bob);
    expect(utp_order(Livewire::test(ListPractices::class)))->toBe($default);
});

it('forgets the saved layout when the user resets the columns', function (): void {
    $user = bl_actor('super_admin');
    utp_freshLogin($user);

    $page = Livewire::test(ListPractices::class);
    $default = utp_defaultOrder($page);
    utp_moveLastToFront($page);
    expect(UserTablePreference::query()->where('user_id', $user->id)->exists())->toBeTrue();

    $page->call('resetTableColumnManager');

    expect(UserTablePreference::query()->where('user_id', $user->id)->exists())->toBeFalse();

    utp_freshLogin($user);
    expect(utp_order(Livewire::test(ListPractices::class)))->toBe($default);
});

it('writes nothing for a user who never changes the layout', function (): void {
    $user = bl_actor('super_admin');
    utp_freshLogin($user);

    Livewire::test(ListPractices::class)->call('sortTable', 'name');

    expect(UserTablePreference::query()->count())->toBe(0)
        ->and(session(UserTablePreferences::OWNER_SESSION_KEY))->toBe($user->id);
});

it('keeps a relation manager table layout too', function (): void {
    $user = bl_actor('super_admin');
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'UTP-' . uniqid(),
        'document_type' => 'R',
        'series_id' => Series::create(['code' => 'UTP-' . substr(uniqid(), -4), 'title' => 'U', 'is_active' => true])->id,
        'repository_id' => $user->default_repository_id,
    ]);
    $mount = fn () => Livewire::test(FlagsRelationManager::class, ['ownerRecord' => $doc, 'pageClass' => EditDocument::class]);

    utp_freshLogin($user);
    $page = $mount();
    $state = collect($page->instance()->tableColumns)
        ->map(fn (array $c): array => $c['name'] === 'resolved_at' ? [...$c, 'isToggled' => false] : $c)
        ->all();
    $page->call('applyTableColumnManager', $state);

    utp_freshLogin($user);

    expect($mount()->instance()->isTableColumnToggledHidden('resolved_at'))->toBeTrue();
});
