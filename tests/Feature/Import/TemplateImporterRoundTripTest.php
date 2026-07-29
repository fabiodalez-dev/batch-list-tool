<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Template ↔ importer round-trip guard.
 *
 * Every column the app writes into a downloadable template MUST be recognised
 * by the matching importer's header guessing — otherwise an operator downloads
 * the template, fills it in, re-imports it, and a column is silently dropped
 * (no error, data just missing). That is exactly what happened with the Box
 * template's `parent_box_number` column: the template emitted it but the
 * importer's `parent_barcode` column did not list it among its guesses, so the
 * parent-box link never imported (Charlene's 2026-07-28 feedback #8).
 *
 * This test drives the SAME guessing the wizard uses
 * ({@see ImportWizard::guessColumnMap()}) over the headers the SAME generator
 * produces ({@see TemplateGenerator::headersFor()}), for the five entities the
 * client imports, and fails if any template column is left unmapped.
 */
uses(RefreshDatabase::class);

/**
 * Columns that appear in a template BY DESIGN but are intentionally not imported.
 * Empty now that every shipped template column maps to an importer field — a
 * genuinely new unmapped column (the drift bug this test exists to catch, e.g.
 * box `parent_box_number`, or the Series ISAD metadata) must fail here.
 *
 * @var array<string, array<int, string>>
 */
const RT_INFORMATIONAL = [];

dataset('template_entities', [
    'series' => ['series', SeriesImporter::class],
    'authority' => ['authority', AuthorityImporter::class],
    'location' => ['location', LocationImporter::class],
    'box' => ['box', BoxImporter::class],
    'batch' => ['batch', BatchImporter::class],
]);

test('every template column is recognised by its importer (no silently-dropped column)', function (string $entity, string $importerClass) {
    $headers = TemplateGenerator::headersFor($entity);
    expect($headers)->not->toBeEmpty();

    $map = ImportWizard::guessColumnMap($importerClass, $headers);

    // Headers actually claimed by an importer column (the map is column => header).
    $claimed = array_values(array_filter($map, static fn ($h): bool => $h !== null && $h !== ''));

    $informational = RT_INFORMATIONAL[$entity] ?? [];
    $unmapped = array_values(array_diff($headers, $claimed, $informational));

    expect($unmapped)->toBe(
        [],
        "These {$entity} template columns are not recognised by {$importerClass} "
        . '(and are not in the documented informational allowlist): ' . implode(', ', $unmapped)
    );
})->with('template_entities');

test('the Box template parent_box_number column maps to the parent barcode importer column', function () {
    // The specific regression from feedback #8: the template's parent column
    // (named parent_box_number, holding the parent RAS barcode) must resolve to
    // the importer's parent_barcode column.
    $headers = TemplateGenerator::headersFor('box');
    expect($headers)->toContain('parent_box_number');

    $map = ImportWizard::guessColumnMap(BoxImporter::class, $headers);

    expect($map['parent_barcode'] ?? null)->toBe('parent_box_number');
});

test('logUnrecognisedHeaders warns about spreadsheet columns the importer will not consume', function () {
    Log::shouldReceive('channel')->with('import')->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'not recognised')
            && in_array('Totally Unknown Column', $context['ignored_columns'], true)
            && ! in_array('Identifier', $context['ignored_columns'], true);
    });

    $headers = ['Identifier', 'Totally Unknown Column'];
    $columnMap = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    ImportWizard::logUnrecognisedHeaders(SeriesImporter::class, $headers, $columnMap, 'test');
});

test('logUnrecognisedHeaders stays silent when every column is recognised', function () {
    Log::shouldReceive('channel')->never();

    $headers = TemplateGenerator::headersFor('batch');
    $columnMap = ImportWizard::guessColumnMap(BatchImporter::class, $headers);

    ImportWizard::logUnrecognisedHeaders(BatchImporter::class, $headers, $columnMap, 'test');
});

test('the Download-template step has a hint for every tricky/known box column', function () {
    $hints = ImportWizard::columnHints();
    // The two that caused the client's confusion must be explained.
    expect($hints)->toHaveKey('parent_box_number')
        ->and($hints['parent_box_number'])->toContain('RAS')
        ->and($hints)->toHaveKey('Location')
        ->and($hints['Location'])->toContain('CODE');

    // Every STATIC box template column that is not free-form has an explanation.
    // Exclude the dynamic custom-field columns the template appends (their labels
    // are operator-defined and cannot have a built-in hint), so the assertion is
    // not coupled to whatever custom fields happen to be active.
    $customFieldLabels = CustomFieldResolver::definitionsFor('box')
        ->pluck('label')
        ->all();
    $noHintNeeded = array_merge(['notes'], $customFieldLabels);

    foreach (TemplateGenerator::headersFor('box') as $h) {
        if (in_array($h, $noHintNeeded, true)) {
            continue;
        }
        expect($hints)->toHaveKey($h);
    }
});

test('the wizard Download-template step actually RENDERS the box column hints', function () {
    // Guards the rendering path (the template_info placeholder), not just the
    // columnHints() data: a regression that stops feeding the hints into the DOM
    // would still leave columnHints() intact but must fail here.
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'boxes')
        ->assertSee('the parent RAS box')   // parent_box_number hint text
        ->assertSee('location CODE');        // Location hint text
});
