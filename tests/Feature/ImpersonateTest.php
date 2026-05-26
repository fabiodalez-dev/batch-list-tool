<?php

use App\Listeners\LogImpersonation;
use App\Models\User;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('super_admin can impersonate', function () {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    expect($user->canImpersonate())->toBeTrue();
});

it('admin cannot impersonate (only super_admin may)', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->canImpersonate())->toBeFalse();
});

it('super_admin cannot be impersonated (privilege escalation guard)', function () {
    $target = User::factory()->create();
    $target->assignRole('super_admin');

    expect($target->canBeImpersonated())->toBeFalse();
});

it('non-super_admin users can be impersonated', function () {
    foreach (['admin', 'editor', 'viewer'] as $role) {
        $target = User::factory()->create();
        $target->assignRole($role);
        expect($target->canBeImpersonated())->toBeTrue("Role {$role} should be impersonatable");
    }
});

it('writes an audit row on TakeImpersonation event', function () {
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $target = User::factory()->create();
    $target->assignRole('editor');

    $beforeCount = Audit::count();

    $listener = new LogImpersonation;
    $listener->handleTake(new TakeImpersonation($actor, $target));

    expect(Audit::count())->toBe($beforeCount + 1);

    $row = Audit::latest('id')->first();
    expect($row->event)->toBe('impersonation_started')
        ->and($row->user_id)->toBe($actor->id)
        ->and($row->auditable_id)->toBe($target->id);
});

it('writes an audit row on LeaveImpersonation event', function () {
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $target = User::factory()->create();
    $target->assignRole('editor');

    $listener = new LogImpersonation;
    $listener->handleLeave(new LeaveImpersonation($actor, $target));

    $row = Audit::latest('id')->first();
    expect($row->event)->toBe('impersonation_ended')
        ->and($row->user_id)->toBe($actor->id)
        ->and($row->auditable_id)->toBe($target->id);
});
