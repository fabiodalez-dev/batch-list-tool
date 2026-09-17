<?php

declare(strict_types=1);

use App\Filament\Pages\ImportStatus;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/*
 * Decision, 2026-09-17, recorded here so it is not re-litigated by accident.
 *
 * Since the resource pages stopped carrying their own upload modal, the wizard
 * is the only way to import. That narrowed who can import: the buttons used to
 * be visible to anyone holding the entity's create permission, while the whole
 * Importation area — wizard, status page, saved profiles — is limited to
 * super_admin and admin.
 *
 * It was left narrow on purpose:
 *
 *   - a bulk import creates or updates thousands of records in one action, and
 *     with the overwrite box ticked it rewrites existing ones;
 *   - the limit is uniform across the area. Widening only the wizard would let
 *     someone start an import and then be unable to see whether it finished or
 *     which rows failed, which is worse than not starting it;
 *   - nobody is affected today: every production user is a super_admin.
 *
 * If an editor ever needs to import, the choice is to grant them admin or to
 * widen the entire area deliberately — not to loosen one page. This test fails
 * if someone loosens one page, which is the point.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function iaa_userWith(string $role): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['email' => 'access+' . uniqid() . '@test.local', 'is_active' => true]);
    $u->assignRole($role);

    return $u;
}

it('lets an admin and a super admin reach the import area', function (string $role): void {
    $this->actingAs(iaa_userWith($role));

    expect(ImportWizard::canAccess())->toBeTrue();
    expect(ImportStatus::canAccess())->toBeTrue();
})->with(['super_admin', 'admin']);

it('keeps an editor and a viewer out of the whole import area, not just part of it', function (string $role): void {
    $this->actingAs(iaa_userWith($role));

    // Whichever way this is changed in future, it has to move together: being
    // able to start an import without being able to see its outcome is a worse
    // position than not being able to start one.
    expect(ImportWizard::canAccess())->toBeFalse();
    expect(ImportStatus::canAccess())->toBeFalse();
})->with(['editor', 'viewer']);

it('hides the resource Import button from anyone the wizard would refuse', function (): void {
    // The button links to the wizard, so showing it to a user who cannot open
    // the wizard would hand them a 403 instead of an import.
    $editor = iaa_userWith('editor');
    $this->actingAs($editor);

    $source = file_get_contents(
        (new ReflectionClass(ListSeries::class))->getFileName()
    );

    expect($source)->toContain('ImportWizard::canAccess()');
});
