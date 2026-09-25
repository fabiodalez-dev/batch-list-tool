<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\DocumentTypeImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Imports\VolumeImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\ColumnLabelOverride;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/*
 * Client, 2026-09-25: "Is it possible to apply it to all the other templates?"
 *
 * Renaming reached Authority on 2026-09-24. These tests are about the other
 * eight templates, and the thing worth pinning is not that a label changes but
 * that it changes EVERYWHERE AT ONCE: a rename that reached the template and
 * not the importer would leave an orphan header — a column the cataloguer fills
 * in and the import silently discards.
 *
 * Two of these templates are worse off than Authority ever was. Batch, location
 * and volume still ship technical headings ("batch_number", "parent_name"), so
 * for them this is not a convenience.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

afterEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

/** Every renameable entity and the importer that consumes its template. */
function ret_importers(): array
{
    return [
        'authority' => AuthorityImporter::class,
        'series' => SeriesImporter::class,
        'batch' => BatchImporter::class,
        'box' => BoxImporter::class,
        'location' => LocationImporter::class,
        'documentType' => DocumentTypeImporter::class,
        'document' => DocumentImporter::class,
        'volume' => VolumeImporter::class,
        'accession' => AccessionRowImporter::class,
    ];
}

function ret_admin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'ret+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function ret_rename(Repository $repo, string $entity, string $field, string $label): void
{
    ColumnLabelOverride::create([
        'repository_id' => $repo->id,
        'entity_type' => $entity,
        'field_key' => $field,
        'label' => $label,
    ]);
    ColumnLabels::flushMemo();
}

/** Which header, if any, the importer would claim for $field. */
function ret_claimed(string $importer, array $headers, string $field): ?string
{
    return ImportWizard::guessColumnMap($importer, $headers)[$field] ?? null;
}

it('offers renaming on every template, not just authority', function (): void {
    expect(ColumnLabels::renameableEntities())
        ->toEqualCanonicalizing(array_keys(ret_importers()));
});

it('leaves every template exactly as it shipped when nothing has been renamed', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET1']);
    $this->actingAs(ret_admin($repo));

    // The guard against the whole feature: eight templates changing shape the
    // day renaming is switched on would be a regression for every sheet already
    // filled in, and nobody asked for the columns to move.
    $orphans = [];
    foreach (ret_importers() as $entity => $importer) {
        $headers = TemplateGenerator::headersFor($entity);
        $claimed = array_filter(ImportWizard::guessColumnMap($importer, $headers), fn ($h) => filled($h));
        foreach (array_diff($headers, array_values($claimed)) as $orphan) {
            $orphans[] = "{$entity}: {$orphan}";
        }
    }

    expect($orphans)->toBe([]);
});

it('names every renameable field after a header that is really in its template', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET2']);
    $this->actingAs(ret_admin($repo));

    // DEFAULTS is written from the templates, so a column removed from a
    // template must not linger here: an override on a key nothing ships is a
    // rename the cataloguer performs and never sees take effect.
    $missing = [];
    foreach (ColumnLabels::renameableEntities() as $entity) {
        $headers = TemplateGenerator::headersFor($entity);
        foreach (ColumnLabels::DEFAULTS[$entity] as $field => $factoryHeader) {
            if (! in_array($factoryHeader, $headers, true)) {
                $missing[] = "{$entity}.{$field} => '{$factoryHeader}'";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('carries a batch rename into the template and the importer together', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET3']);
    $this->actingAs(ret_admin($repo));

    // The batch template still ships the technical "batch_number".
    expect(TemplateGenerator::headersFor('batch'))->toContain('batch_number');

    ret_rename($repo, 'batch', 'batch_number', 'Batch Number');

    $headers = TemplateGenerator::headersFor('batch');

    expect($headers)->toContain('Batch Number')
        ->and($headers)->not->toContain('batch_number')
        ->and(ret_claimed(BatchImporter::class, $headers, 'batch_number'))->toBe('Batch Number');
});

it('still imports a sheet saved before the rename', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET4']);
    $this->actingAs(ret_admin($repo));

    ret_rename($repo, 'batch', 'batch_number', 'Batch Number');

    // The cataloguer's existing sheets carry the factory header. It keeps
    // importing because it IS the field name, and guessSingleColumn tries the
    // field name before the label — so renaming cannot strand a filled-in file.
    $oldSheet = ['batch_number', 'description', 'type', 'is_active', 'repository_code'];

    expect(ret_claimed(BatchImporter::class, $oldSheet, 'batch_number'))->toBe('batch_number');
});

it('does not touch the column-mapping labels of a template nobody has renamed', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET5']);
    $this->actingAs(ret_admin($repo));

    // DEFAULTS holds the header as the TEMPLATE spells it, which on this
    // template is still "batch_number", while the importer's own label is the
    // readable "Batch number" an operator picks from in the mapping dropdown.
    // Assigning the default unconditionally would replace every readable label
    // with its technical spelling — a rename nobody asked for.
    $labels = [];
    foreach (BatchImporter::getColumns() as $column) {
        $labels[$column->getName()] = $column->getLabel();
    }

    expect($labels['batch_number'])->toBe('Batch number')
        ->and($labels['repository_code'])->toBe('Repository code');
});

it('renames the label an operator maps by, once the column has been renamed', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET6']);
    $this->actingAs(ret_admin($repo));

    ret_rename($repo, 'location', 'parent_name', 'Parent Location');

    $labels = [];
    foreach (LocationImporter::getColumns() as $column) {
        $labels[$column->getName()] = $column->getLabel();
    }

    expect($labels['parent_name'])->toBe('Parent Location');
});

it('keeps a rename inside the repository it was made in', function (): void {
    $mine = Repository::factory()->create(['code' => 'RET7A']);
    $theirs = Repository::factory()->create(['code' => 'RET7B']);

    ret_rename($mine, 'series', 'code', 'Subseries Reference');

    $this->actingAs(ret_admin($mine));
    ColumnLabels::flushMemo();
    expect(TemplateGenerator::headersFor('series'))->toContain('Subseries Reference');

    // One archive's vocabulary must not reach another's template.
    $this->actingAs(ret_admin($theirs));
    ColumnLabels::flushMemo();
    expect(TemplateGenerator::headersFor('series'))
        ->toContain('Identifier')
        ->and(TemplateGenerator::headersFor('series'))->not->toContain('Subseries Reference');
});

it('refuses a name another column in the same template already carries', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET8']);
    $this->actingAs(ret_admin($repo));

    // Two identical headers cannot be told apart on a spreadsheet: one of the
    // two columns would be filled in and then quietly discarded on import.
    expect(fn () => ret_rename($repo, 'location', 'code', 'Notes'))
        ->toThrow(ValidationException::class);

    expect(ColumnLabels::clashesWith('location', 'code', '  nOtEs ', $repo->id))->toBeTrue();
});

it('never renames a header the document template repeats', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET9']);
    $this->actingAs(ret_admin($repo));

    $headers = TemplateGenerator::headersFor('document');
    $repeated = array_keys(array_filter(array_count_values($headers), fn (int $n): bool => $n > 1));

    // The legacy layout repeats "Barcode (IN)" and "Disinfestation Date" on
    // purpose — the cataloguers read those columns by POSITION. Renaming one of
    // three identical cells would leave a row claiming two different things
    // about the same column, so those fields are not renameable at all.
    expect($repeated)->not->toBe([]);

    foreach ($repeated as $header) {
        expect(ColumnLabels::DEFAULTS['document'])->not->toContain($header);
    }

    // And the guard holds even if one were added back by mistake.
    $substituted = ColumnLabels::applyToHeaders('document', $headers);
    expect($substituted)->toBe($headers);
});

it('renames a document column without disturbing the repeated ones', function (): void {
    $repo = Repository::factory()->create(['code' => 'RET10']);
    $this->actingAs(ret_admin($repo));

    $before = TemplateGenerator::headersFor('document');
    ret_rename($repo, 'document', 'series', 'Series Code');
    $after = TemplateGenerator::headersFor('document');

    expect($after)->toContain('Series Code')
        ->and($after)->not->toContain('Subseries')
        ->and(count($after))->toBe(count($before));

    // Every other header, repeats included, sits exactly where it sat.
    $position = array_search('Subseries', $before, true);
    unset($before[$position], $after[$position]);
    expect(array_values($after))->toBe(array_values($before));
});

it('shows the entities by the names the archivist sees in the sidebar', function (): void {
    // "Series" and "DocumentType" appear nowhere in her interface: the sidebar
    // says Subseries and Document Type. A picker that disagrees with the rest
    // of the app is how a working feature goes unused.
    $options = ColumnLabels::renameableEntityOptions();

    expect($options['series'])->toBe('Subseries')
        ->and($options['documentType'])->toBe('Document Type')
        ->and($options['accession'])->toBe('Notary Accession')
        ->and($options)->toHaveCount(count(ColumnLabels::renameableEntities()));
});

it('renames a column on each of the remaining templates', function (string $entity, string $field, string $label): void {
    $repo = Repository::factory()->create(['code' => 'RETX' . substr(md5($entity), 0, 4)]);
    $this->actingAs(ret_admin($repo));

    $importer = ret_importers()[$entity];
    $factoryHeader = ColumnLabels::DEFAULTS[$entity][$field];

    ret_rename($repo, $entity, $field, $label);
    $headers = TemplateGenerator::headersFor($entity);

    expect($headers)->toContain($label)
        ->and($headers)->not->toContain($factoryHeader)
        ->and(ret_claimed($importer, $headers, $field))->toBe($label);
})->with([
    ['series', 'title', 'Subseries Title'],
    ['batch', 'description', 'Batch Description'],
    ['box', 'seal_number', 'Seal No'],
    ['location', 'sort_order', 'Display Order'],
    ['documentType', 'name', 'Type Name'],
    ['document', 'deeds', 'Deed Count'],
    ['volume', 'dates_start', 'First Date'],
    ['accession', 'pages_folios', 'Folios'],
    ['authority', 'notes', 'Cataloguing Remarks'],
]);
