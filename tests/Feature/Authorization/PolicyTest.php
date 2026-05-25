<?php

declare(strict_types=1);

use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * PR #11b — Filament Shield + Spatie Permission policies.
 *
 * Shield generates one Policy class per Filament Resource that delegates
 * to spatie/laravel-permission permissions named:
 *   view_any_{model}, view_{model}, create_{model},
 *   update_{model}, delete_{model}, delete_any_{model}, …
 *
 * The InitialDataSeeder syncs:
 *   - admin      = ALL permissions
 *   - editor     = view_/create_/update_/reorder_
 *   - viewer     = view_ only
 *   - super_admin = bypasses every gate via Gate::before (Shield convention)
 *
 * These tests pin those properties at the Gate level.
 */

uses(DatabaseTransactions::class);

function rolesExist_pol(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function user_pol(string $role): User
{
    rolesExist_pol();
    $u = User::factory()->create([
        'email'     => $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);
    return $u;
}

/* 69. super_admin bypasses everything */
test('super_admin bypasses every gate via Shield Gate::before', function () {
    $u = user_pol('super_admin');

    // Shield's Gate::before maps every defined ability to true for super_admin.
    expect(Gate::forUser($u)->allows('view_any_document'))->toBeTrue();
    expect(Gate::forUser($u)->allows('delete_any_document'))->toBeTrue();
    expect(Gate::forUser($u)->allows('view_any_repository'))->toBeTrue();
    expect(Gate::forUser($u)->allows('delete_any_repository'))->toBeTrue();
    // hasRole('super_admin') is itself the bypass marker
    expect($u->hasRole('super_admin'))->toBeTrue();
});

/*
 * 70. admin can do most things; but the project does NOT ship a
 * delete-super-admin-user safeguard (no UserResource yet) — instead we
 * pin that admin has the broad permission set Shield generates.
 */
test('admin has every Shield permission (RFQ §3.5.1 oversight)', function () {
    $u = user_pol('admin');

    // Every resource's view_any_/delete_any_ should be granted
    foreach (['document', 'authority', 'batch', 'box', 'series', 'accession', 'repository'] as $r) {
        expect($u->hasPermissionTo("view_any_$r"))->toBeTrue("missing view_any_$r");
        expect($u->hasPermissionTo("delete_any_$r"))->toBeTrue("missing delete_any_$r");
    }
});

/* 71. editor can CRUD Documents but cannot delete Repositories */
test('editor can CRUD Documents but cannot delete repositories', function () {
    $u = user_pol('editor');

    // Editor has view_/create_/update_/reorder_ on every resource.
    expect($u->hasPermissionTo('view_any_document'))->toBeTrue();
    expect($u->hasPermissionTo('create_document'))->toBeTrue();
    expect($u->hasPermissionTo('update_document'))->toBeTrue();

    // Editor does NOT have delete_* permissions
    expect($u->hasPermissionTo('delete_document'))->toBeFalse();
    expect($u->hasPermissionTo('delete_any_document'))->toBeFalse();

    // Editor does NOT have any delete_repository permission either
    expect($u->hasPermissionTo('delete_repository'))->toBeFalse();
    expect($u->hasPermissionTo('delete_any_repository'))->toBeFalse();
});

/* 72. viewer can only READ */
test('viewer can only view — no create/update/delete', function () {
    $u = user_pol('viewer');

    expect($u->hasPermissionTo('view_any_document'))->toBeTrue();
    expect($u->hasPermissionTo('view_document'))->toBeTrue();

    expect($u->hasPermissionTo('create_document'))->toBeFalse();
    expect($u->hasPermissionTo('update_document'))->toBeFalse();
    expect($u->hasPermissionTo('delete_document'))->toBeFalse();
    expect($u->hasPermissionTo('delete_any_document'))->toBeFalse();
});

/* 73. A Policy class exists for every resource model. */
test('Shield has generated a Policy class for every Filament Resource', function () {
    $expected = [
        Document::class    => \App\Policies\DocumentPolicy::class,
        Authority::class   => \App\Policies\AuthorityPolicy::class,
        Box::class         => \App\Policies\BoxPolicy::class,
        Batch::class       => \App\Policies\BatchPolicy::class,
        Series::class      => \App\Policies\SeriesPolicy::class,
        Accession::class   => \App\Policies\AccessionPolicy::class,
        Repository::class  => \App\Policies\RepositoryPolicy::class,
    ];

    foreach ($expected as $model => $policy) {
        expect(class_exists($policy))->toBeTrue("Missing policy: $policy for $model");
        // Each policy has the conventional Shield method signature
        expect(method_exists($policy, 'viewAny'))->toBeTrue("$policy lacks viewAny()");
        expect(method_exists($policy, 'create'))->toBeTrue("$policy lacks create()");
        expect(method_exists($policy, 'update'))->toBeTrue("$policy lacks update()");
        expect(method_exists($policy, 'delete'))->toBeTrue("$policy lacks delete()");
    }
});

/* 74. Unauthenticated user is redirected to login when hitting /admin */
test('Unauthenticated user is redirected to login on /admin', function () {
    $resp = $this->get('/admin');
    // 302 to /admin/login (Filament default) — assert redirect status, not URL
    $resp->assertStatus(302);
});

/*
 * 75. Authenticated user without any role is denied panel access.
 *
 * User::canAccessPanel returns false when the user has no role assigned
 * AND is_active is true (the active gate alone is not enough). Filament's
 * middleware then returns 403 — but in practice the redirect-to-login
 * fires first because the panel auth fails. We assert either is acceptable
 * (302 to /admin/login OR 403) per the documented contract.
 */
test('Authenticated user without any role cannot access /admin', function () {
    rolesExist_pol();
    $u = User::factory()->create(['is_active' => true]); // NO ->assignRole(...)

    expect($u->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
        ->toBeFalse();

    $this->actingAs($u);
    $resp = $this->get('/admin');
    // Filament responds 403 when canAccessPanel() is false on an authed user
    expect(in_array($resp->getStatusCode(), [302, 403], true))->toBeTrue();
});
