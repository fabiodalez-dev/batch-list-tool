<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Support\EqualsFirst;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-19:
 *   1. NRA 202: "These documents have been disinfested twice … the system is
 *      showing the original disinfestation date of the first box".
 *   2. "Actual Authority Identifier – change to Alternate Reference Code (MS)".
 *   3. "Cannot find Deeds column. Found No of Acts … I think its redundant".
 *   4. "How to search for not in box documents, Mounted; no Box, Conservation
 *      Box? I think these were in the Custody Status".
 *   5. "Can default be Equals instead of contains for all options?"
 *   7. "Document Filter – cannot see all – I think it needs to change to add
 *      what fields I want similar to Boxes".
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

/** @param array<string, string> $data */
function f19_import(array $data, int $userId): ?Document
{
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($imp, ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data)), []))($data);

    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $data['Document Identifier'])->first();
}

it('gives a document disinfested twice its latest date, keeping the first one', function (): void {
    $doc = f19_import(['Document Identifier' => 'F19-1', 'Subseries' => 'REG', 'Disinfestation Date' => '24/10/2024', 'Disinfestation Date (3)' => '24/07/2026'], $this->user->id);

    expect($doc->disinfestation_date->format('Y-m-d'))->toBe('2026-07-24')
        ->and($doc->disinfestation_date_1->format('Y-m-d'))->toBe('2024-10-24')
        ->and($doc->disinfestation_date_3->format('Y-m-d'))->toBe('2026-07-24');
});

it('imports Conservation Box as a custody status and filters the list by it', function (): void {
    $doc = f19_import(['Document Identifier' => 'F19-2', 'Subseries' => 'REG', 'Custody Status' => 'Conservation Box'], $this->user->id);
    $other = f19_import(['Document Identifier' => 'F19-3', 'Subseries' => 'REG', 'Custody Status' => 'Not in Box'], $this->user->id);

    expect($doc->custody_status)->toBe('conservation_box');

    Livewire::test(ListDocuments::class)
        ->filterTable('custody_status', ['conservation_box'])
        ->assertCanSeeTableRecords([$doc])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableColumnFormattedStateSet('custody_status', 'Conservation Box', $doc);
});

it('renames the Alternate Reference Code column, shows Deeds and drops No of Acts', function (): void {
    Livewire::test(ListDocuments::class)
        ->assertTableColumnExists('authorities.alternative_identifier_warrant', fn ($c): bool => $c->getLabel() === 'Alternate Reference Code (MS)')
        ->assertTableColumnExists('deeds', fn ($c): bool => ! $c->isToggledHiddenByDefault())
        ->assertTableColumnDoesNotExist('number_of_acts');
});

it('starts every text and number rule on Equals', function (): void {
    expect(array_key_first(EqualsFirst::text('x', 'X')->getOperators()))->toBe('equals')
        ->and(array_key_first(EqualsFirst::number('y', 'Y')->getOperators()))->toBe('equals');

    $page = Livewire::test(ListDocuments::class);
    $builder = collect($page->instance()->getTable()->getFilters())->first(fn ($f): bool => $f instanceof QueryBuilder);
    $constraints = $builder->getConstraints();
    foreach (['practice', 'volume_number', 'deeds', 'tracking', 'museum_location', 'citation_reference', 'creator_surname', 'authority_citing_code', 'authority_alternate_code', 'disinfestation_date', 'digitised'] as $name) {
        expect($constraints)->toHaveKey($name);
    }
    expect(array_key_first($constraints['practice']->getOperators()))->toBe('equals');
});

it('finds documents with an Equals rule on a field of the sheet', function (): void {
    $keep = f19_import(['Document Identifier' => 'F19-4', 'Subseries' => 'REG', 'Practice' => 'NTG'], $this->user->id);
    $drop = f19_import(['Document Identifier' => 'F19-5', 'Subseries' => 'REG', 'Practice' => 'NTG-X'], $this->user->id);

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', ['rules' => [
            'r1' => ['type' => 'practice', 'data' => ['operator' => 'equals', 'settings' => ['text' => 'NTG']]],
        ]])
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$drop]);
});

it('imports all three "Disinfestation Date" columns of the sheet, through the wizard', function (): void {
    // The three columns share one header. The column map was guessed from the
    // raw header row, so the 2nd and 3rd were never mapped: no document in
    // production had a 2nd or 3rd disinfestation date.
    $csv = "Subseries,Disinfestation Date,Disinfestation Date,Disinfestation Date\nREG,24/10/2024,,24/07/2026\n";

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.csv', $csv))
        ->set('data.confirm', true)
        ->call('startImport')
        ->assertNotified('Import started');

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->firstOrFail();
    expect($doc->disinfestation_date_1->format('Y-m-d'))->toBe('2024-10-24')
        ->and($doc->disinfestation_date_3->format('Y-m-d'))->toBe('2026-07-24')
        ->and($doc->disinfestation_date->format('Y-m-d'))->toBe('2026-07-24');
});
