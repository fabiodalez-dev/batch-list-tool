<?php

declare(strict_types=1);

use App\Filament\Resources\AuditResource;
use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Models\Document;
use App\Models\Repository;
use App\Models\User;
use App\Policies\AuditPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use OwenIt\Auditing\Models\Audit;

use function Pest\Livewire\livewire;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Security audit 2026-11-13, lead 5:
 *  (a) AuditPolicy was never bound to the vendor Audit model, so the
 *      view_any_audit / view_audit permissions did not gate the audit log;
 *  (b) the audit log was not scoped by repository, so a user restricted to
 *      repository A read the old/new values of repository B's records.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function sa_auditFor(Document $doc, string $secret): Audit
{
    return Audit::query()->create([
        'event' => 'updated',
        'auditable_type' => $doc->getMorphClass(),
        'auditable_id' => $doc->id,
        'old_values' => ['notes' => 'old'],
        'new_values' => ['notes' => $secret],
    ]);
}

function sa_restrictedUser(string $role, Repository $repo): User
{
    /** @var User $user */
    $user = User::factory()->create(['default_repository_id' => $repo->id, 'is_active' => true]);
    $user->assignRole($role);
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $user;
}

it('binds AuditPolicy to the Audit model', function () {
    expect(Gate::getPolicyFor(Audit::class))->toBeInstanceOf(AuditPolicy::class);
});

it('keeps the audit log closed to a role without the audit permissions', function () {
    Role::findByName('viewer', 'web')->revokePermissionTo(['view_any_audit', 'view_audit']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $repo = qf_repo('A');
    $audit = sa_auditFor(qf_doc(['repository_id' => $repo->id]), 'A-NOTE');
    $this->actingAs(sa_restrictedUser('viewer', $repo));

    expect(AuditResource::canViewAny())->toBeFalse()
        ->and(AuditResource::canView($audit))->toBeFalse();
    $this->get('/admin/audits')->assertForbidden();
});

it('shows a repository-restricted user only the audits of their repositories', function (string $role) {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $own = sa_auditFor(qf_doc(['repository_id' => $a->id]), 'A-NOTE');
    $foreign = sa_auditFor(qf_doc(['repository_id' => $b->id]), 'B-SECRET');

    $this->actingAs(sa_restrictedUser($role, $a));

    livewire(ListAudits::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(AuditResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    $this->get(AuditResource::getUrl('view', ['record' => $own]))->assertOk();
})->with(['editor', 'viewer']);

it('keeps the full audit log for admin', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $one = sa_auditFor(qf_doc(['repository_id' => $a->id]), 'A-NOTE');
    $two = sa_auditFor(qf_doc(['repository_id' => $b->id]), 'B-NOTE');

    /** @var User $admin */
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    livewire(ListAudits::class)->assertCanSeeTableRecords([$one, $two]);
});
