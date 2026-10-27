<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportStatus;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ImportCsvSlice;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\LocationBreadcrumbCache;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-27, "Batch List Issues.docx": Museum Location by name,
 * Name Suffix and Maiden Surname, MAV/NRA boxes in Documents, Batch/Box in
 * number order, the Current box filter, all creators, Digitised links, Past
 * Reference Code as a rule, Conservation Object Reference Number as a Drive
 * link, and the 767 rows a finished import never reached.
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
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
});

function b27_batch(string $n): Batch
{
    return Batch::withoutGlobalScopes()->firstOrCreate(['batch_number' => $n], ['type' => 'MAIN_COLLECTION', 'repository_id' => test()->repo->id]);
}

function b27_box(string $type, string $number, ?Batch $batch = null): Box
{
    return Box::withoutGlobalScopes()->create(array_filter([
        'box_type' => $type, 'box_number' => $number, 'batch_id' => $batch?->id,
        'repository_id' => $batch === null ? test()->repo->id : null,
        'barcode' => $type === 'RAS' ? 'BC' . $type . $number . ($batch?->batch_number ?? '') : null,
        'barcode_status' => 'IN', 'is_legacy' => $type !== 'RAS', 'provenance_unknown' => $type !== 'RAS' ? true : null,
    ], fn ($v) => $v !== null));
}

function b27_doc(array $attrs = []): Document
{
    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'BLI-' . strtoupper(substr(uniqid(), -8)),
        'repository_id' => test()->repo->id,
        'series_id' => test()->series->id,
    ], $attrs));
}

it('names a MAV box by its type in the Box column and finds documents by box type', function (): void {
    BoxType::query()->updateOrCreate(['code' => 'MAV'], ['label' => 'MAV', 'is_active' => true, 'is_legacy' => true, 'sort_order' => 0]);
    $b28 = b27_batch('28');
    $mav = b27_box('MAV', '1');
    $ras = b27_box('RAS', '1', $b28);
    $inMav = b27_doc(['batch_id' => $b28->id, 'current_box_id' => $mav->id]);
    $inRas = b27_doc(['batch_id' => $b28->id, 'current_box_id' => $ras->id]);

    Livewire::test(ListDocuments::class)
        ->assertTableColumnFormattedStateSet('currentBox.box_number', 'MAV 1', $inMav)
        ->assertTableColumnFormattedStateSet('currentBox.box_number', '1', $inRas)
        ->filterTable('queryBuilder', ['rules' => [
            'r1' => ['type' => 'box_type', 'data' => ['operator' => 'is', 'settings' => ['values' => ['MAV']]]],
        ]])
        ->assertCanSeeTableRecords([$inMav])
        ->assertCanNotSeeTableRecords([$inRas]);
});

it('sorts Batch and Box in number order, 1, 2, 8, 10, 100', function (): void {
    $b30 = b27_batch('30');
    $docs = collect(['100', '8', '10', '1', '2'])->mapWithKeys(fn (string $n): array => [$n => b27_doc(['batch_id' => $b30->id, 'current_box_id' => b27_box('RAS', $n, $b30)->id])]);

    Livewire::test(ListDocuments::class)
        ->sortTable('currentBox.box_number')
        ->assertCanSeeTableRecords([$docs['1'], $docs['2'], $docs['8'], $docs['10'], $docs['100']], inOrder: true);
});

it('labels the Current box filter by batch, and filters to that box only', function (): void {
    $b12 = b27_batch('12');
    $b13 = b27_batch('13');
    $box12 = b27_box('RAS', '109', $b12);
    $box13 = b27_box('RAS', '109', $b13);
    $in12 = b27_doc(['batch_id' => $b12->id, 'current_box_id' => $box12->id]);
    $in13 = b27_doc(['batch_id' => $b13->id, 'current_box_id' => $box13->id]);

    expect($box12->fresh()->shortLabel())->toBe('Batch 12 / Box 109');

    Livewire::test(ListDocuments::class)
        ->filterTable('batch', [$b12->id])
        ->filterTable('current_box_id', [$box12->id])
        ->assertCanSeeTableRecords([$in12])
        ->assertCanNotSeeTableRecords([$in13]);
});

it('shows every creator with the suffix after the surname, the museum location by name, the Digitised link and a Drive link', function (): void {
    $doc = b27_doc(['museum_location' => 'MUSE-1-33', 'digitised' => 'VHMML', 'digitised_url' => 'https://w3id.org/vhmml/readingRoom/view/126946', 'object_reference_number' => '96/0116/GDG/AAV']);
    Location::withoutGlobalScopes()->create(['name' => 'Showcase 6', 'code' => 'MUSE-1-33', 'type' => 'showcase', 'repository_id' => $this->repo->id, 'depth' => 0, 'is_active' => true]);
    foreach ([['Giuseppe', 'Grech', null], ['Carmelo', 'Farrugia', null], ['Cristoforo', 'Frendo', 'Senior'], ['Pietro', 'Zammit', null]] as $i => [$given, $surname, $suffix]) {
        $doc->authorities()->attach(Authority::create(['identifier' => 'NAM-' . $i, 'alternative_identifier' => 'R' . $i, 'surname' => $surname, 'given_names' => $given, 'name_suffix' => $suffix, 'entity_type' => 'PERSON'])->id);
    }

    Livewire::test(ListDocuments::class)
        ->assertTableColumnStateSet('creator_names', ['Giuseppe Grech', 'Carmelo Farrugia', 'Cristoforo Frendo Senior', 'Pietro Zammit'], $doc)
        ->assertDontSee('and 1 more')
        ->assertTableColumnFormattedStateSet('museum_location', 'Showcase 6', $doc)
        ->assertTableColumnStateSet('digitised', 'https://w3id.org/vhmml/readingRoom/view/126946', $doc);

    expect(Document::driveSearchUrl('96/0116/GDG/AAV'))->toBe('https://drive.google.com/drive/search?q=%2296%2F0116%2FGDG%2FAAV%22');
});

it('never names a museum location from another repository that uses the same code', function (): void {
    $other = Repository::create(['name' => 'Other archive', 'code' => 'OTH']);
    Location::withoutGlobalScopes()->create(['name' => 'Other vault', 'code' => 'MUSE-9-99', 'type' => 'showcase', 'repository_id' => $other->id, 'depth' => 0, 'is_active' => true]);

    expect(LocationBreadcrumbCache::forCode('MUSE-9-99', $this->repo->id))->toBe('MUSE-9-99')
        ->and(LocationBreadcrumbCache::forCode('MUSE-9-99', $other->id))->toBe('Other vault');
});

it('filters documents by a custom field such as Past Reference Code', function (): void {
    $def = CustomFieldDefinition::create(['repository_id' => $this->repo->id, 'entity_type' => 'document', 'key' => 'past_reference_code', 'label' => 'Past Reference Code', 'type' => 'text', 'is_active' => true, 'sort_order' => 0]);
    $keep = b27_doc();
    $drop = b27_doc();
    CustomFieldValue::create(['custom_field_definition_id' => $def->id, 'customizable_type' => Document::class, 'customizable_id' => $keep->id, 'value' => 'R532/001_IDX']);
    CustomFieldValue::create(['custom_field_definition_id' => $def->id, 'customizable_type' => Document::class, 'customizable_id' => $drop->id, 'value' => 'R999/002']);

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', ['rules' => [
            'r1' => ['type' => 'cf_past_reference_code', 'data' => ['operator' => 'equals', 'settings' => ['text' => 'R532/001_IDX']]],
        ]])
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$drop]);
});

it('keeps a waiting import slice off the queue for 20 s instead of retrying it at once', function (): void {
    $import = Import::query()->create(['file_name' => 'f.csv', 'file_path' => '/tmp/f.csv', 'importer' => DocumentImporter::class, 'total_rows' => 1, 'user_id' => $this->user->id]);
    $lock = collect((new ImportCsvSlice($import, '/tmp/f.csv', 0, 1, 1, []))->middleware())->first();

    expect($lock)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($lock->releaseAfter)->toBe(20)
        ->and($lock->expiresAfter)->toBe(120);
});

it('says on the Import Status page how many rows a finished import never reached', function (): void {
    Import::query()->create(['file_name' => 'documents.xlsx', 'file_path' => '/tmp/d.csv', 'importer' => DocumentImporter::class, 'total_rows' => 26867, 'processed_rows' => 26100, 'successful_rows' => 25585, 'completed_at' => now(), 'user_id' => $this->user->id]);

    Livewire::test(ImportStatus::class)->assertSee('767 rows were never imported');
});
