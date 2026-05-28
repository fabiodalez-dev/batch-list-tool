<?php

declare(strict_types=1);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Field-level permission matrix admin page (RFQ §3.1.8)
|--------------------------------------------------------------------------
*/

it('opens the field-permission matrix for an admin', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('Field-level permission matrix');
});

it('forbids an editor from the field-permission matrix', function () {
    bl_login(bl_actor('editor'))->navigate('/admin/field-permissions')->assertSee('403');
});

it('forbids a viewer from the field-permission matrix', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin/field-permissions')->assertSee('403');
});

it('lists the document resource in the matrix', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('document');
});

it('lists the box resource in the matrix', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('box');
});

it('shows the RFQ Administrator role label', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('Administrator');
});

it('shows the RFQ ReadingRoom role label', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('ReadingRoom');
});

it('shows the RFQ General role label', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('General');
});

it('shows the Save changes action', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('Save changes');
});

it('shows the Reset to config defaults action', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('Reset to config defaults');
});

it('shows the sensitive extra field row in the matrix', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('extra');
});

it('shows the repository_id tenant field row in the matrix', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/field-permissions')->assertSee('repository_id');
});
