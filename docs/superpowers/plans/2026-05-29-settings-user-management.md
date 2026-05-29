# Settings & User Management — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Filament-native Settings area: admin user management (new `UserResource`), branding/backup/audit settings via `spatie/laravel-settings`, per-user activity logging, and account self-service.

**Architecture:** Filament 5 Resources/Pages grouped under `Administration` and `My account` nav groups. Reuse existing Shield (roles), Fortify (profile/2FA), `owen-it/laravel-auditing` (activity). New global settings stored with `spatie/laravel-settings`. All privileged actions audited; multi-repository scope preserved.

**Tech Stack:** Laravel 13.11 · Filament 5.6 · filament-shield 4.2 · Fortify 1.37 · spatie/laravel-permission 7.4 · spatie/laravel-settings (new) · owen-it/laravel-auditing · Pest · PHP 8.4.

**Spec:** `docs/superpowers/specs/2026-05-29-settings-user-management-design.md`
**Branch:** `feat/settings-user-management`

**Filament 5 conventions (mirror `app/Filament/Resources/RepositoryResource.php`):**
- Forms/infolists use `Filament\Schemas\Schema` (`form(Schema $schema): Schema`), `Filament\Schemas\Components\Section`.
- Table/row actions live in `Filament\Actions\*` (e.g. `EditAction`, `BulkActionGroup`).
- Resource pages mirror `RepositoryResource/Pages/{List,Create,View,Edit}*.php`.
- Custom admin pages mirror `app/Filament/Pages/FieldPermissionMatrix.php` (`canAccess()` gating + Blade view + header actions).
- Policy methods mirror `app/Policies/RepositoryPolicy.php` (`$authUser->can('<action>_<subject>')`).
- Auth-event listeners mirror `app/Listeners/LogImpersonation.php` / `LogTwoFactorChange.php`.

---

## Task 1: `must_change_password` column + User model

**Files:**
- Create: `database/migrations/2026_05_29_120000_add_must_change_password_to_users.php`
- Modify: `app/Models/User.php` (fillable + casts)
- Test: `tests/Feature/Settings/UserModelTest.php`

- [ ] **Step 1 — Failing test**
```php
<?php // tests/Feature/Settings/UserModelTest.php
use App\Models\User;
it('casts must_change_password and defaults to false', function () {
    $u = User::factory()->create();
    expect($u->must_change_password)->toBeFalse();
    $u->update(['must_change_password' => true]);
    expect($u->fresh()->must_change_password)->toBeTrue();
});
```
- [ ] **Step 2 — Run, expect FAIL** (`php artisan test --filter=UserModelTest`) — column missing.
- [ ] **Step 3 — Migration**
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('must_change_password')->default(false)->after('is_active'));
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
    }
};
```
- [ ] **Step 4 — User model:** add `'must_change_password'` to `$fillable`; add `'must_change_password' => 'boolean'` to `casts()`.
- [ ] **Step 5 — Run, expect PASS.**
- [ ] **Step 6 — Commit** `feat(users): add must_change_password column`.

## Task 2: User permissions + `UserPolicy`

**Files:**
- Create: `app/Policies/UserPolicy.php`
- Modify: `database/seeders/InitialDataSeeder.php` (ensure `*_user` perms assigned to admin; editor/viewer none)
- Test: `tests/Feature/Settings/UserPolicyTest.php`

- [ ] **Step 1 — Failing test** covering: super_admin & admin can `viewAny`/`create`; editor/viewer cannot; admin cannot `update`/`delete` a super_admin; nobody can delete themselves.
```php
<?php
use App\Models\User;
beforeEach(fn () => $this->seed(\Database\Seeders\InitialDataSeeder::class));
function makeUser(string $role): User { $u = User::factory()->create(); $u->assignRole($role); return $u; }
it('lets admin manage users but not super_admins', function () {
    $admin = makeUser('admin'); $sa = makeUser('super_admin'); $target = makeUser('editor');
    expect($admin->can('create', User::class))->toBeTrue();
    expect($admin->can('update', $target))->toBeTrue();
    expect($admin->can('update', $sa))->toBeFalse();
    expect($admin->can('delete', $admin))->toBeFalse(); // no self-delete
    expect(makeUser('viewer')->can('viewAny', User::class))->toBeFalse();
});
```
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement `UserPolicy`** (mirror RepositoryPolicy, add guards):
```php
<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
class UserPolicy {
    use HandlesAuthorization;
    public function viewAny(AuthUser $u): bool { return $u->can('view_any_user'); }
    public function view(AuthUser $u, User $m): bool { return $u->can('view_user'); }
    public function create(AuthUser $u): bool { return $u->can('create_user'); }
    public function update(AuthUser $u, User $m): bool {
        if (! $u->can('update_user')) return false;
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) return false;
        return true;
    }
    public function delete(AuthUser $u, User $m): bool {
        if (! $u->can('delete_user')) return false;
        if ($u->is($m)) return false; // no self-delete
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) return false;
        return true;
    }
    public function deleteAny(AuthUser $u): bool { return $u->can('delete_any_user'); }
    public function restore(AuthUser $u, User $m): bool { return $u->can('restore_user'); }
    public function forceDelete(AuthUser $u, User $m): bool { return $u->can('force_delete_user') && ! $u->is($m); }
}
```
- [ ] **Step 4 — Run `php artisan shield:generate --all --panel=admin`** then `php artisan test --filter=UserPolicyTest`. Expect PASS (perms now exist; admin role gets `*_user` from shield generation — if not, add to seeder explicitly).
- [ ] **Step 5 — Commit** `feat(users): UserPolicy with anti-escalation + self-protect`.

## Task 3: `UserResource` (form/table/infolist/pages)

**Files:**
- Create: `app/Filament/Resources/UserResource.php` + `UserResource/Pages/{ListUsers,CreateUser,ViewUser,EditUser}.php`
- Test: `tests/Feature/Settings/UserResourceTest.php`

- [ ] **Step 1 — Failing test:** admin can render Create page and create a user with role+repositories; `must_change_password` is true; default repo must be within assigned.
```php
<?php
use App\Models\{User,Repository};
use function Pest\Livewire\livewire;
use App\Filament\Resources\UserResource\Pages\CreateUser;
beforeEach(function () { $this->seed(\Database\Seeders\InitialDataSeeder::class); $this->admin = User::factory()->create(); $this->admin->assignRole('admin'); $this->actingAs($this->admin); });
it('creates a user with role and repositories', function () {
    $repo = Repository::factory()->create();
    livewire(CreateUser::class)->fillForm([
        'name' => 'Maria Borg', 'email' => 'maria@nra.test',
        'password' => 'TempPass!234', 'passwordConfirmation' => 'TempPass!234',
        'role' => 'editor', 'repositories' => [$repo->id], 'default_repository_id' => $repo->id, 'is_active' => true,
    ])->call('create')->assertHasNoFormErrors();
    $u = User::where('email','maria@nra.test')->first();
    expect($u)->not->toBeNull()
        ->and($u->must_change_password)->toBeTrue()
        ->and($u->hasRole('editor'))->toBeTrue()
        ->and($u->repositories->pluck('id'))->toContain($repo->id);
});
```
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement `UserResource`** (mirror RepositoryResource shape; `navigationGroup = 'Administration'`, `navigationSort = 10`, `navigationIcon = 'heroicon-o-users'`). Form sections: Identity (name, email unique ignoring record), Initial access (password — `->password()->revealable()->dehydrateStateUsing(fn($s)=>Hash::make($s))->dehydrated(fn($s)=>filled($s))->required(fn(string $op)=>$op==='create')`, plus a "Generate" suffix action filling a random string; `must_change_password` Toggle default true, disabled on create), Role & access (role `Select` of `roleOptions()` excluding super_admin for non-super-admins; `repositories` `Select->multiple()->relationship('repositories','name')`; `default_repository_id` `Select` options from selected repositories; `is_active` Toggle). Persist role in `afterCreate`/`afterSave` via `syncRoles([$state['role']])`. (Full code authored during execution following the RepositoryResource template + spec §3.2.)
- [ ] **Step 4 — Pages:** create the 4 page classes mirroring `RepositoryResource/Pages/*`. `CreateUser` sets `must_change_password=true` in `mutateFormDataBeforeCreate`.
- [ ] **Step 5 — Run, expect PASS.**
- [ ] **Step 6 — Commit** `feat(users): UserResource CRUD with role + repository assignment`.

## Task 4: Escalation & self-protection guards in the form

**Files:** Modify `app/Filament/Resources/UserResource.php`; Test: `tests/Feature/Settings/UserEscalationTest.php`

- [ ] **Step 1 — Failing tests:** (a) `roleOptions()` for an admin excludes `super_admin`; (b) editing self hides the deactivate/role controls (assert form state / policy); (c) a posted `role=super_admin` from an admin is rejected.
```php
<?php
use App\Models\User;
beforeEach(fn () => $this->seed(\Database\Seeders\InitialDataSeeder::class));
it('hides super_admin role from admins', function () {
    $admin = User::factory()->create(); $admin->assignRole('admin'); $this->actingAs($admin);
    expect(array_keys(\App\Filament\Resources\UserResource::roleOptions()))->not->toContain('super_admin');
});
```
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** `public static function roleOptions(): array` returning `RoleLabels` map filtered: drop `super_admin` unless `auth()->user()->hasRole('super_admin')`. Validate the submitted role server-side in `mutateFormDataBeforeCreate/Save` (throw `ValidationException` if escalating). Disable `is_active`/role when `$record?->is($currentUser)`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(users): enforce role-escalation + self-protection in form`.

## Task 5: Reset-password & activate/deactivate actions

**Files:** Modify `UserResource.php` (table/header actions); Test: `tests/Feature/Settings/UserActionsTest.php`

- [ ] **Step 1 — Failing test:** "Reset password" action sets a new hash + `must_change_password=true`; "Deactivate" sets `is_active=false`; cannot deactivate self.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** `Action::make('resetPassword')` (generate temp pwd, `update(['password'=>Hash::make($tmp),'must_change_password'=>true])`, notify with the temp value) and `Action::make('toggleActive')` guarded by `->visible(fn(User $r)=>! $r->is(auth()->user()))`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(users): reset-password + activate/deactivate actions`.

## Task 6: Force password change on first login

**Files:**
- Create: `app/Http/Middleware/EnsurePasswordChanged.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (append middleware); `app/Actions/Fortify/UpdateUserPassword.php` (clear flag)
- Test: `tests/Feature/Settings/ForcePasswordChangeTest.php`

- [ ] **Step 1 — Failing test:** a user with `must_change_password=true` hitting `/admin` is redirected to the change-password route; after changing, flag is cleared and dashboard loads.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement middleware** (allow logout + the profile/password + livewire routes; redirect everything else to the Filament profile page with a warning notification when `must_change_password`). Append to panel `->middleware([... EnsurePasswordChanged::class])`. In `UpdateUserPassword::update()` set `$user->forceFill(['must_change_password'=>false])`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(auth): force password change on first login`.

## Task 7: Repository ↔ users relation manager

**Files:** Create `app/Filament/Resources/RepositoryResource/RelationManagers/UsersRelationManager.php`; Modify `RepositoryResource::getRelations()`; Test: `tests/Feature/Settings/RepositoryUsersRelationTest.php`

- [ ] **Step 1 — Failing test:** attach/detach a user to a repository via the relation manager.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** RelationManager on `users` with attach/detach + `is_default` pivot column; register in `getRelations()`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(repositories): manage assigned users`.

## Task 8: Install `spatie/laravel-settings` + settings classes

**Files:**
- `composer require spatie/laravel-settings` + publish; Create `app/Settings/{BrandingSettings,BackupSettings,AuditSettings}.php` + settings migrations under `database/settings/`.
- Test: `tests/Feature/Settings/SettingsClassesTest.php`

- [ ] **Step 1 — Failing test:** `app(BrandingSettings::class)->brand_name` returns the seeded default `'Batch List Tool'`.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Install & implement** the 3 settings classes (typed props) + a settings migration seeding current defaults (brand_name, logo path, logo_height `2.25rem`, primary_color `#A5613D`; backup keep_daily 16 / weekly 8 / monthly 4; audit enabled true, threshold 0). Register the settings migration path.
- [ ] **Step 4 — Run `php artisan migrate`, expect PASS.**
- [ ] **Step 5 — Commit** `feat(settings): install spatie/laravel-settings + branding/backup/audit settings`.

## Task 9: Branding settings page + apply in panel

**Files:** Create `app/Filament/Pages/Settings/BrandingPage.php` (+ Blade or schema form); Modify `AdminPanelProvider`; Test: `tests/Feature/Settings/BrandingTest.php`

- [ ] **Step 1 — Failing test:** saving `brand_name='NRA Archive'` makes `BrandingSettings::brand_name` persist; panel `brandName` reflects it; an external URL logo is rejected (local only).
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** a settings page (SettingsPage from the package, gated admin) with brand_name, logo (media upload — local disk), logo_height, primary_color; in `AdminPanelProvider::panel()` read `app(BrandingSettings::class)` with fallbacks to current defaults.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(settings): branding page applied panel-wide`.

## Task 10: Backup & health page

**Files:** Create `app/Filament/Pages/Settings/BackupHealthPage.php` + widget(s); Test: `tests/Feature/Settings/BackupHealthTest.php`

- [ ] **Step 1 — Failing test:** "Run backup now" is gated to admin/super_admin and dispatches `backup:run` (fake the queue/Artisan and assert called); retention values persist via `BackupSettings`.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** page: read last-backup status from `spatie/laravel-backup` + the `/health` checks; `Action::make('runBackup')` → `Artisan::queue('backup:run')` guarded; retention form bound to `BackupSettings`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(settings): backup & health page with run-now`.

## Task 11: Audit settings page

**Files:** Create `app/Filament/Pages/Settings/AuditSettingsPage.php`; Modify audit read path (e.g. `config/audit.php` reading `AuditSettings`); Test: `tests/Feature/Settings/AuditSettingsTest.php`

- [ ] **Step 1 — Failing test:** toggling `AuditSettings::enabled=false` stops new `Audit` rows being written on a model update.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** page (super_admin only) bound to `AuditSettings`; make auditing honour `AuditSettings::enabled` (e.g. in `AppServiceProvider` set `Config::set('audit.enabled', app(AuditSettings::class)->enabled)` at boot, or a resolver).
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(settings): audit settings page`.

## Task 12: Auth-event activity logging

**Files:** Create `app/Listeners/LogAuthenticationEvent.php`; Modify `app/Providers/AppServiceProvider.php` (or EventServiceProvider) to register; Test: `tests/Feature/Settings/AuthActivityTest.php`

- [ ] **Step 1 — Failing test:** a successful `Login` and a `Failed` login each create an activity/audit record with the user (or attempted email) + ip.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** a listener (mirror `LogImpersonation`) subscribed to `Illuminate\Auth\Events\{Login,Logout,Failed,Lockout,PasswordReset}` writing an audit/activity row (event name, user_id/email, ip, ua).
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(audit): log authentication events`.

## Task 13: Per-user activity tab + deep link

**Files:** Create `app/Filament/Resources/UserResource/RelationManagers/ActivityRelationManager.php` (read-only over `audits` where `user_id`); Modify `UserResource::getRelations()`; Test: `tests/Feature/Settings/UserActivityTabTest.php`

- [ ] **Step 1 — Failing test:** a user's update action appears in their activity tab; the tab is read-only.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** read-only relation manager listing the user's `audits` (event, auditable_type, changes summary, ip, created_at), no create/edit/delete; add a header action linking to `AuditResource` filtered by `user_id`.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(users): per-user activity tab`.

## Task 14: My account › Preferences (default repository)

**Files:** Create `app/Filament/Pages/Account/PreferencesPage.php`; Test: `tests/Feature/Settings/PreferencesTest.php`

- [ ] **Step 1 — Failing test:** a user can set `default_repository_id` only to a repository they belong to; an unauthorised repo is rejected.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement** page (`navigationGroup='My account'`, `canAccess=auth()->check()`) with a `Select` of the user's repositories writing `default_repository_id`; validate membership server-side.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `feat(account): default-repository preference`.

## Task 15: Navigation cleanup

**Files:** Modify the resources whose group is `'Reference data'` to use `'Reference'`; verify `Administration`/`My account`/`Settings` grouping is coherent. Test: `tests/Feature/Settings/NavigationTest.php` (assert no resource uses `'Reference data'`).

- [ ] **Step 1 — Failing test** asserting `LocationResource::getNavigationGroup()` (and any other) equals `'Reference'`.
- [ ] **Step 2 — Run, expect FAIL.**
- [ ] **Step 3 — Implement:** rename `'Reference data'` → `'Reference'` where used.
- [ ] **Step 4 — Run, expect PASS.**
- [ ] **Step 5 — Commit** `refactor(nav): unify Reference nav group`.

## Task 16: Full suite + lint

- [ ] **Step 1 — Run** `vendor/bin/pint --dirty` then `php artisan test` (full) + `vendor/bin/phpstan analyse` if configured. Expect all green.
- [ ] **Step 2 — Fix** any regressions.
- [ ] **Step 3 — Commit** `test: settings & user management suite green`.
- [ ] **Step 4 — Push branch & open PR** (CI: Tests & Quality, RFQ suite, Browser E2E, Security must pass; asset-build runs on merge).

---

## Self-review notes
- **Spec coverage:** §2 nav→T15; §3 users→T1-T6; §3.5 repo↔users→T7; §4 settings storage→T8; §5 branding→T9; §6 backup→T10; §7 audit→T11; §8 activity→T12-T13; §9 preferences→T14; §10 testing→every task + T16. All covered.
- **Escalation invariants** are enforced in three layers (policy T2, form options T4, server-side mutate T4) — consistent property name `roleOptions()` used in T3/T4.
- **Flag name** `must_change_password` consistent across T1/T3/T5/T6.
- Boilerplate Filament page/Resource code is authored at execution time strictly following the cited existing templates (RepositoryResource, FieldPermissionMatrix) — note for the executor, not a placeholder for logic.
