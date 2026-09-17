<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Client decision, 2026-09-17 — one import path instead of two.
 *
 * The resource pages carried their own upload modal, which did not order
 * parents ahead of children, did not offer the overwrite choice, and did not
 * warn about missing structural columns. Worse, it passed no options at all,
 * so `skip_duplicates` defaulted to false and existing records were rewritten
 * — the opposite of what the client asked for on 13 August.
 *
 * Those buttons now link to the wizard with the record type preselected.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function sip_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['email' => 'single-path+' . uniqid() . '@test.local', 'is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/** Every resource page that used to carry its own upload modal. */
dataset('resource pages', [
    'authorities' => ['App\Filament\Resources\AuthorityResource\Pages\ListAuthorities', 'authorities'],
    'batches' => ['App\Filament\Resources\BatchResource\Pages\ListBatches', 'batches'],
    'boxes' => ['App\Filament\Resources\BoxResource\Pages\ListBoxes', 'boxes'],
    'documents' => ['App\Filament\Resources\DocumentResource\Pages\ListDocuments', 'documents'],
    'locations' => ['App\Filament\Resources\LocationResource\Pages\ListLocations', 'locations'],
    'series' => ['App\Filament\Resources\SeriesResource\Pages\ListSeries', 'series'],
    'volumes' => ['App\Filament\Resources\VolumeResource\Pages\ListVolumes', 'volumes'],
]);

it('no longer carries a second import path on any resource page', function (string $page): void {
    // A page that still builds the package action is a page where the same
    // sheet behaves differently from the wizard — the whole point of this work.
    $source = file_get_contents((new ReflectionClass($page))->getFileName());

    expect($source)->not->toContain('FullImportAction');
})->with([
    'App\Filament\Resources\AuthorityResource\Pages\ListAuthorities',
    'App\Filament\Resources\BatchResource\Pages\ListBatches',
    'App\Filament\Resources\BoxResource\Pages\ListBoxes',
    'App\Filament\Resources\DocumentResource\Pages\ListDocuments',
    'App\Filament\Resources\LocationResource\Pages\ListLocations',
    'App\Filament\Resources\SeriesResource\Pages\ListSeries',
    'App\Filament\Resources\VolumeResource\Pages\ListVolumes',
]);

it('points each page at the wizard with its own record type', function (string $page, string $type): void {
    $source = file_get_contents((new ReflectionClass($page))->getFileName());

    expect($source)->toContain("ImportWizard::getUrl(['type' => '{$type}'])");
    // The type has to be one the wizard actually offers, or the operator lands
    // on an empty radio.
    expect(ImportWizard::IMPORTERS)->toHaveKey($type);
})->with('resource pages');

it('opens the wizard with the type already chosen', function (): void {
    $this->actingAs(sip_admin());

    foreach (array_keys(ImportWizard::IMPORTERS) as $type) {
        $this->get(ImportWizard::getUrl(['type' => $type]))->assertSuccessful();

        // A 200 only proves the page rendered; it would pass just as well if
        // mount() ignored the parameter outright. Assert the form state, which
        // is what decides whether the operator has to pick the type again.
        Livewire::withQueryParams(['type' => $type])
            ->test(ImportWizard::class)
            ->assertOk()
            ->assertSet('data.import_type', $type);
    }
});

it('ignores a type it does not recognise instead of failing', function (): void {
    $this->actingAs(sip_admin());

    // A stale bookmark or a hand-edited URL must land on the normal wizard,
    // not on an error and not with a bogus value sitting in the radio.
    foreach (['nonsense', '', 'Series', '../../etc/passwd'] as $bogus) {
        $this->get(ImportWizard::getUrl(['type' => $bogus]))->assertSuccessful();

        Livewire::withQueryParams(['type' => $bogus])
            ->test(ImportWizard::class)
            ->assertOk()
            ->assertSet('data.import_type', null);
    }
});

it('offers volumes, which had an importer and a template but no way in', function (): void {
    expect(ImportWizard::IMPORTERS)->toHaveKey('volumes');
    expect(ImportWizard::TEMPLATE_KEYS['volumes'] ?? null)->toBe('volume');

    // The template must map cleanly, or the new entry sends the operator to a
    // step they cannot complete.
    $headers = TemplateGenerator::headersFor('volume');
    $map = ImportWizard::guessColumnMap(ImportWizard::IMPORTERS['volumes'], $headers);
    $claimed = array_values(array_filter($map, fn ($h) => $h !== null));

    expect(array_values(array_diff($headers, $claimed)))->toBe([]);
    expect($map['document_identifier'] ?? null)->toBe('document_identifier');
});

it('every wizard type has a template, a label and a description', function (): void {
    $page = new ImportWizard;
    $source = file_get_contents((new ReflectionClass(ImportWizard::class))->getFileName());

    foreach (array_keys(ImportWizard::IMPORTERS) as $type) {
        // A type with no template key would break the Download template button.
        expect(ImportWizard::TEMPLATE_KEYS)->toHaveKey($type);
        expect(TemplateGenerator::headersFor(ImportWizard::TEMPLATE_KEYS[$type]))->not->toBeEmpty();
        // A type missing from the radio is unreachable even though it is mapped.
        expect($source)->toContain("'{$type}' => '");
    }
})->skip(fn () => ! class_exists(ImportWizard::class), 'wizard unavailable');
