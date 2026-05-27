<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feature 2 — Onboarding Import Wizard (multi-step Filament Wizard).
 *
 * The Wizard is hard to assert against idiomatically — its state lives
 * inside a Livewire component, and the steps are gated on user input we
 * can only simulate by typing one field at a time. So this file is
 * deliberately a smoke-test: we mount the Page, confirm it boots, and
 * exercise the two pure helpers (`canAccess`, `guessColumnMap`,
 * `findMissingRequiredColumns`) that the orchestrator relies on.
 *
 * Deeper end-to-end coverage of the actual import dispatch path lives
 * in `tests/Feature/BulkImportV2Test.php`, which exercises the five
 * Filament Importers directly without round-tripping through the UI.
 */
uses(RefreshDatabase::class);

function iw_user(string $role): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'iw-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

test('wizard canAccess returns true for super_admin', function () {
    $this->actingAs(iw_user('super_admin'));
    expect(ImportWizard::canAccess())->toBeTrue();
});

test('wizard canAccess returns true for admin', function () {
    $this->actingAs(iw_user('admin'));
    expect(ImportWizard::canAccess())->toBeTrue();
});

test('wizard canAccess returns false for viewer', function () {
    $this->actingAs(iw_user('viewer'));
    expect(ImportWizard::canAccess())->toBeFalse();
});

test('wizard canAccess returns false for editor', function () {
    $this->actingAs(iw_user('editor'));
    expect(ImportWizard::canAccess())->toBeFalse();
});

test('wizard Page mounts without errors for an admin', function () {
    $this->actingAs(iw_user('super_admin'));

    Livewire::test(ImportWizard::class)->assertOk();
});

test('guessColumnMap picks up headers via importer guess() aliases', function () {
    $headers = ['Identifier', 'Standard title in English (Plural)', 'Description'];
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect($map)
        ->toHaveKey('code')
        ->and($map['code'])->toBe('Identifier')
        ->and($map['title'])->toBe('Standard title in English (Plural)');
});

test('findMissingRequiredColumns reports columns whose mapping is null', function () {
    // Empty headers → every requiredMapping column is missing.
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, []);
    $missing = ImportWizard::findMissingRequiredColumns(AuthorityImporter::class, $map);

    // `identifier` is a requiredMapping column on AuthorityImporter.
    expect($missing)->not()->toBeEmpty()
        ->and($missing)->toContain('Identifier');
});

test('IMPORTERS map covers every advertised wizard option', function () {
    // 'locations' added in PR #85 (LocationImporter) — keep this list in
    // sync with ImportWizard::IMPORTERS / TEMPLATE_KEYS so a future drop
    // or rename of an entity surfaces as a hard test failure.
    $expected = ['series', 'authorities', 'locations', 'batches', 'boxes', 'documents'];

    expect(array_keys(ImportWizard::IMPORTERS))
        ->toEqualCanonicalizing($expected)
        ->and(array_keys(ImportWizard::TEMPLATE_KEYS))
        ->toEqualCanonicalizing($expected);
});
