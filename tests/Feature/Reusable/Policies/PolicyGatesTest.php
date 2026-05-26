<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: Policy gates — viewer denied for write, admin allowed.
 *
 * Each policy delegates via $user->can('<op>_<resource>'). Shield's seeded
 * permission set grants:
 *   - admin     : all permissions
 *   - editor    : view/create/update/reorder
 *   - viewer    : view_* only
 *   - super_admin: all (via giveSuperAdminPermission())
 *
 * One test per policy. Both assertions: admin can create_*, viewer cannot.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function pol_makeUser(string $role): User
{
    $u = User::factory()->create([
        'email' => 'pol-' . $role . '-' . uniqid() . '@t.t',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

it('PolicyGates: accession — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_accession'))->toBeTrue()
        ->and($viewer->can('create_accession'))->toBeFalse();
});

it('PolicyGates: authority — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_authority'))->toBeTrue()
        ->and($viewer->can('create_authority'))->toBeFalse();
});

it('PolicyGates: batch — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_batch'))->toBeTrue()
        ->and($viewer->can('create_batch'))->toBeFalse();
});

it('PolicyGates: box — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_box'))->toBeTrue()
        ->and($viewer->can('create_box'))->toBeFalse();
});

it('PolicyGates: box_movement — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_box_movement'))->toBeTrue()
        ->and($viewer->can('create_box_movement'))->toBeFalse();
});

it('PolicyGates: document — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_document'))->toBeTrue()
        ->and($viewer->can('create_document'))->toBeFalse();
});

it('PolicyGates: document_flag — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_document_flag'))->toBeTrue()
        ->and($viewer->can('create_document_flag'))->toBeFalse();
});

it('PolicyGates: location — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_location'))->toBeTrue()
        ->and($viewer->can('create_location'))->toBeFalse();
});

it('PolicyGates: repository — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_repository'))->toBeTrue()
        ->and($viewer->can('create_repository'))->toBeFalse();
});

it('PolicyGates: series — admin can create, viewer cannot', function () {
    $admin = pol_makeUser('admin');
    $viewer = pol_makeUser('viewer');
    expect($admin->can('create_series'))->toBeTrue()
        ->and($viewer->can('create_series'))->toBeFalse();
});
