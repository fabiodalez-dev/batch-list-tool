<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\TwoFactorEnrolment;
use App\Filament\Pages\FieldPermissionMatrix;
use App\Filament\Pages\TwoFactorProfile;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\RepositoryResource;

/**
 * Navigation consolidation — updated for the Charlene nav restructure
 * (feat/nav-restructure-and-doctype-ux).
 *
 * The old 'Reference data' / 'Settings' / 'Account' groups were retired in an
 * earlier pass; this pass retires 'Archive' / 'Reference' / 'Lookups' and
 * introduces the Records / Classifications / Operations / Importation / Reports
 * / My Account / Administration groups.
 */
it('LocationResource is now in the Records group', function () {
    expect(LocationResource::getNavigationGroup())->toBe('Records');
});

it('RepositoryResource is now in the Records group', function () {
    expect(RepositoryResource::getNavigationGroup())->toBe('Records');
});

it('FieldPermissionMatrix is in Administration group', function () {
    expect(FieldPermissionMatrix::getNavigationGroup())->toBe('Administration');
});

it('TwoFactorProfile is in My Account group', function () {
    expect(TwoFactorProfile::getNavigationGroup())->toBe('My Account');
});

it('TwoFactorEnrolment is in My Account group', function () {
    expect(TwoFactorEnrolment::getNavigationGroup())->toBe('My Account');
});

it('no resource or page still lives in a retired group', function () {
    foreach ([LocationResource::class, RepositoryResource::class] as $resource) {
        expect($resource::getNavigationGroup())
            ->not->toBe('Reference data')
            ->not->toBe('Reference')
            ->not->toBe('Archive')
            ->not->toBe('Lookups')
            ->not->toBe('Settings');
    }
});

it('the My Account group uses the capitalised label', function () {
    expect(TwoFactorProfile::getNavigationGroup())->toBe('My Account')
        ->and(TwoFactorEnrolment::getNavigationGroup())->toBe('My Account');
});

it('TwoFactorProfile and TwoFactorEnrolment have distinct navigation labels', function () {
    expect(TwoFactorProfile::getNavigationLabel())->not->toBe(TwoFactorEnrolment::getNavigationLabel());
});

it('Records sort order is correct', function () {
    expect(RepositoryResource::getNavigationSort())->toBe(10)
        ->and(LocationResource::getNavigationSort())->toBe(20);
});

it('My Account sort order is correct', function () {
    expect(TwoFactorProfile::getNavigationSort())->toBe(10);
});
