<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\AuthorityResource\Pages\CreateAuthority;
use App\Filament\Resources\AuthorityResource\Pages\EditAuthority;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Models\Authority;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * PR #11b — Resource coverage for App\Filament\Resources\AuthorityResource.
 *
 * Authority is a *reference* model (no BelongsToRepository scope) — the
 * multi-tenant test below documents that fact rather than asserting visible
 * separation, because the repository scope simply does not apply to
 * Authority by design (it is shared across all repositories).
 *
 * Convention: DatabaseTransactions, mirroring the existing SecurityBaseline
 * tests so the dev seed survives.
 */

uses(DatabaseTransactions::class);

/* ----------------------------------------------------------------------- */
/* Helpers (suffixed _auth to avoid name collisions with sibling files)    */
/* ----------------------------------------------------------------------- */

function rolesExist_auth(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsAdmin_auth(): User
{
    rolesExist_auth();
    $u = User::factory()->create([
        'email'     => 'auth-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');
    return $u;
}

function makeAuthority_auth(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier'  => 'AT-' . strtoupper(substr(uniqid(), -8)),
        'surname'     => 'Surname' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
    ], $attrs));
}

/* ----------------------------------------------------------------------- */
/* 1. List page renders                                                     */
/* ----------------------------------------------------------------------- */

test('AuthorityResource list page renders', function () {
    $this->actingAs(actAsAdmin_auth());

    $existing = makeAuthority_auth();

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$existing]);
});

/* ----------------------------------------------------------------------- */
/* 2. Create form renders with required fields                              */
/* ----------------------------------------------------------------------- */

test('AuthorityResource create form renders with required fields', function () {
    $this->actingAs(actAsAdmin_auth());

    Livewire::test(CreateAuthority::class)
        ->assertOk()
        ->assertFormFieldExists('identifier')
        ->assertFormFieldExists('surname');
});

/* ----------------------------------------------------------------------- */
/* 3. Valid create persists row                                             */
/* ----------------------------------------------------------------------- */

test('AuthorityResource valid create persists row', function () {
    $this->actingAs(actAsAdmin_auth());

    $identifier = 'AT-NEW-' . strtoupper(substr(uniqid(), -6));

    Livewire::test(CreateAuthority::class)
        ->fillForm([
            'identifier'  => $identifier,
            'surname'     => 'Borg',
            'entity_type' => 'PERSON',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', $identifier)->exists())->toBeTrue();
});

/* ----------------------------------------------------------------------- */
/* 4. Duplicate identifier validation                                       */
/* ----------------------------------------------------------------------- */

test('AuthorityResource rejects duplicate identifier (unique DB constraint)', function () {
    $this->actingAs(actAsAdmin_auth());

    $existing = makeAuthority_auth(['identifier' => 'DUP-' . uniqid()]);

    // The Filament form does not declare ->unique() on identifier (only DB
    // unique constraint enforces it). Verify that a second insert with the
    // same identifier fails at the DB layer.
    try {
        Authority::create([
            'identifier'  => $existing->identifier,
            'surname'     => 'Another',
            'entity_type' => 'PERSON',
        ]);
        // If we reach here, uniqueness is NOT enforced — fail the test.
        $this->fail('Expected uniqueness violation on duplicate identifier, but insert succeeded.');
    } catch (\Throwable $e) {
        expect($e)->toBeInstanceOf(\Illuminate\Database\QueryException::class);
        expect(strtolower($e->getMessage()))->toContain('unique');
    }
});

/* ----------------------------------------------------------------------- */
/* 5. Edit page loads existing record                                       */
/* ----------------------------------------------------------------------- */

test('AuthorityResource edit page loads existing record', function () {
    $this->actingAs(actAsAdmin_auth());

    $authority = makeAuthority_auth(['surname' => 'EditMe']);

    Livewire::test(EditAuthority::class, ['record' => $authority->getRouteKey()])
        ->assertOk()
        ->assertFormSet(['surname' => 'EditMe']);
});

/* ----------------------------------------------------------------------- */
/* 6. Update persists changes + writes audit row                            */
/* ----------------------------------------------------------------------- */

test('AuthorityResource update persists changes + writes owen-it audit row', function () {
    // owen-it/laravel-auditing treats the Pest/phpunit process as console
    // context — disabled by default. Mirror DashboardWidgetsTest pattern:
    // enable for this test so we can assert the audit row is written.
    config(['audit.console' => true]);

    $this->actingAs(actAsAdmin_auth());

    $authority = makeAuthority_auth(['surname' => 'Before']);
    $beforeAudits = Audit::query()
        ->where('auditable_type', Authority::class)
        ->where('auditable_id', $authority->id)
        ->count();

    Livewire::test(EditAuthority::class, ['record' => $authority->getRouteKey()])
        ->fillForm(['surname' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($authority->refresh()->surname)->toBe('After');

    $afterAudits = Audit::query()
        ->where('auditable_type', Authority::class)
        ->where('auditable_id', $authority->id)
        ->count();
    expect($afterAudits)->toBeGreaterThan($beforeAudits);
});

/* ----------------------------------------------------------------------- */
/* 7. Delete soft-deletes                                                   */
/* ----------------------------------------------------------------------- */

test('AuthorityResource delete soft-deletes the record', function () {
    $authority = makeAuthority_auth();
    $id = $authority->id;

    // Soft-delete directly through the model (the Resource delegates to it)
    $authority->delete();

    expect(Authority::find($id))->toBeNull();
    $trashed = Authority::withTrashed()->find($id);
    expect($trashed)->not->toBeNull();
    expect($trashed->deleted_at)->not->toBeNull();
});

/* ----------------------------------------------------------------------- */
/* 8. Multi-tenant scope                                                    */
/* ----------------------------------------------------------------------- */

test('Authority is intentionally NOT repository-scoped (shared reference data)', function () {
    // Authority does not use the BelongsToRepository trait — by design it is
    // shared across all repositories so the same notary can appear in
    // documents owned by different repos. This test pins that contract so a
    // future refactor that adds the trait would break visibly.
    expect(in_array(
        \App\Models\Concerns\BelongsToRepository::class,
        class_uses_recursive(Authority::class),
        true,
    ))->toBeFalse();

    // And the table has no `repository_id` column.
    expect(\Schema::hasColumn('authorities', 'repository_id'))->toBeFalse();
});
