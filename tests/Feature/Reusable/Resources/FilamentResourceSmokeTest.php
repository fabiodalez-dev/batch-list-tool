<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\RepositoryResource\Pages\ListRepositories;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Reusable: Filament Resource generic smoke tests.
 *
 * One test per Resource: the List page renders for a super_admin without
 * 500 errors. This pins the resource boot path, table column registration,
 * default sort, and policy gates against a super_admin user.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function frs_makeSuperAdmin(): User
{
    $u = User::factory()->create([
        'email' => 'frs-' . uniqid() . '@t.t',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

it('Resource smoke: ListBatches renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListBatches::class)->assertOk();
});

it('Resource smoke: ListDocuments renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListDocuments::class)->assertOk();
});

it('Resource smoke: ListBoxes renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListBoxes::class)->assertOk();
});

it('Resource smoke: ListAccessions renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListAccessions::class)->assertOk();
});

it('Resource smoke: ListAuthorities renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListAuthorities::class)->assertOk();
});

it('Resource smoke: ListSeries renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListSeries::class)->assertOk();
});

it('Resource smoke: ListRepositories renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListRepositories::class)->assertOk();
});

it('Resource smoke: ListLocations renders for super_admin', function () {
    $this->actingAs(frs_makeSuperAdmin());
    Livewire::test(ListLocations::class)->assertOk();
});
