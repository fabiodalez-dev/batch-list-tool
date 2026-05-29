<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\TwoFactorEnrolment;
use App\Filament\Pages\FieldPermissionMatrix;
use App\Filament\Pages\TwoFactorProfile;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\RepositoryResource;

/**
 * Task 15 — Navigation consolidation.
 *
 * Verifies that sidebar navigation groups have been consolidated:
 *   - 'Reference data'  → eliminated (LocationResource moved to 'Reference')
 *   - 'Settings'        → eliminated (RepositoryResource + FieldPermissionMatrix → 'Administration',
 *                                      TwoFactorProfile → 'My account')
 *   - 'Account'         → eliminated (TwoFactorEnrolment moved to 'My account')
 */
it('LocationResource is in Reference group', function () {
    expect(LocationResource::getNavigationGroup())->toBe('Reference');
});

it('RepositoryResource is in Administration group', function () {
    expect(RepositoryResource::getNavigationGroup())->toBe('Administration');
});

it('FieldPermissionMatrix is in Administration group', function () {
    expect(FieldPermissionMatrix::getNavigationGroup())->toBe('Administration');
});

it('TwoFactorProfile is in My account group', function () {
    expect(TwoFactorProfile::getNavigationGroup())->toBe('My account');
});

it('TwoFactorEnrolment is in My account group', function () {
    expect(TwoFactorEnrolment::getNavigationGroup())->toBe('My account');
});

it('no resource or page still lives in the old Reference data group', function () {
    expect(LocationResource::getNavigationGroup())->not->toBe('Reference data');
});

it('no resource or page still lives in the old Settings group', function () {
    expect(RepositoryResource::getNavigationGroup())->not->toBe('Settings');
    expect(FieldPermissionMatrix::getNavigationGroup())->not->toBe('Settings');
    expect(TwoFactorProfile::getNavigationGroup())->not->toBe('Settings');
});

it('no resource or page still lives in the old Account group', function () {
    expect(TwoFactorEnrolment::getNavigationGroup())->not->toBe('Account');
});

it('TwoFactorProfile and TwoFactorEnrolment have distinct navigation labels', function () {
    expect(TwoFactorProfile::getNavigationLabel())->not->toBe(TwoFactorEnrolment::getNavigationLabel());
});

it('Administration sort order is correct', function () {
    expect(RepositoryResource::getNavigationSort())->toBe(20)
        ->and(FieldPermissionMatrix::getNavigationSort())->toBe(40);
});

it('My account sort order is correct', function () {
    expect(TwoFactorProfile::getNavigationSort())->toBe(10)
        ->and(TwoFactorEnrolment::getNavigationSort())->toBe(20);
});
