<?php

declare(strict_types=1);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Authentication & access control (RFQ §3.3 roles, §3.1.7 hardening)
|--------------------------------------------------------------------------
*/

it('redirects an unauthenticated visitor away from the dashboard', function () {
    visit('/admin')->assertSee('Sign in');
});

it('renders the login form with email, password and the CAPTCHA challenge', function () {
    visit('/admin/login')
        ->assertSee('Sign in')
        ->assertSee('Security check');
});

it('lets a super_admin reach the dashboard', function () {
    $admin = bl_actor('super_admin');

    bl_login($admin)->assertPathIs('/admin')->assertSee('Dashboard');
});

it('lets an admin reach the dashboard', function () {
    bl_login(bl_actor('admin'))->assertPathIs('/admin')->assertSee('Dashboard');
});

it('lets an editor reach the dashboard', function () {
    bl_login(bl_actor('editor'))->assertPathIs('/admin')->assertSee('Dashboard');
});

it('lets a viewer reach the dashboard', function () {
    bl_login(bl_actor('viewer'))->assertPathIs('/admin')->assertSee('Dashboard');
});

it('lets an admin open the Import Wizard page', function () {
    bl_login(bl_actor('admin'))
        ->navigate('/admin/import-wizard')
        ->assertSee('Import Wizard');
});

it('forbids an editor from the Import Wizard page', function () {
    bl_login(bl_actor('editor'))
        ->navigate('/admin/import-wizard')
        ->assertSee('403');
});

it('forbids a viewer from the Import Wizard page', function () {
    bl_login(bl_actor('viewer'))
        ->navigate('/admin/import-wizard')
        ->assertSee('403');
});

it('lets an admin open the Field permissions page', function () {
    bl_login(bl_actor('admin'))
        ->navigate('/admin/field-permissions')
        ->assertSee('Field');
});

it('forbids a viewer from the Field permissions page', function () {
    bl_login(bl_actor('viewer'))
        ->navigate('/admin/field-permissions')
        ->assertSee('403');
});

it('lets an admin open the Documents list from the panel', function () {
    bl_login(bl_actor('admin'))
        ->navigate('/admin/documents')
        ->assertSee('Documents');
});
