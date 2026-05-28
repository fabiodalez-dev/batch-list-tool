<?php

declare(strict_types=1);

use App\Models\Authority;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Authorities — notaries / creators reference data (RFQ §1)
|--------------------------------------------------------------------------
*/

function bl_authority(array $overrides = []): Authority
{
    static $n = 0;
    $n++;

    return Authority::create(array_merge([
        'identifier' => 'R' . (1000 + $n),
        'surname' => 'Abela',
        'entity_type' => 'PERSON',
    ], $overrides));
}

it('shows a seeded authority surname on the list', function () {
    bl_authority(['surname' => 'Buttigieg']);

    bl_login(bl_actor('super_admin'))->navigate('/admin/authorities')->assertSee('Buttigieg');
});

it('opens an authority view page showing its identifier', function () {
    $a = bl_authority(['identifier' => 'R-AUTH-1', 'surname' => 'Albano']);

    bl_login(bl_actor('super_admin'))->navigate("/admin/authorities/{$a->id}")->assertSee('R-AUTH-1');
});

it('opens an authority edit page', function () {
    $a = bl_authority(['surname' => 'Canciur']);

    bl_login(bl_actor('super_admin'))->navigate("/admin/authorities/{$a->id}/edit")->assertSee('Canciur');
});

it('lets an admin open the authority create page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/authorities/create')->assertSee('Create');
});

it('forbids a viewer from creating an authority', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin/authorities/create')->assertSee('403');
});

it('shows the alternative identifier (MS code)', function () {
    $a = bl_authority(['identifier' => 'R-AUTH-MS', 'alternative_identifier' => 'MS511', 'surname' => 'Abela']);

    bl_login(bl_actor('super_admin'))->navigate("/admin/authorities/{$a->id}")->assertSee('MS511');
});

it('lists multiple authorities', function () {
    bl_authority(['surname' => 'Zammit']);
    bl_authority(['surname' => 'Vella']);

    bl_login(bl_actor('super_admin'))->navigate('/admin/authorities')
        ->assertSee('Zammit')
        ->assertSee('Vella');
});

it('renders the authorities list heading', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/authorities')->assertSee('Authorities');
});
