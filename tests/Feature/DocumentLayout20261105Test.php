<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\BoxResource\Pages\ViewBox;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Client 2026-11-05, issue 3 and the view part of issue 10: the same sections,
 * in the same order, on New, Edit and View.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create(['code' => 'NRA']);
    $this->series = Series::factory()->create(['code' => 'REG', 'title' => 'Registers']);
    $this->actingAs(bl_actor('super_admin', $this->repo));
});

/**
 * @param array<string, mixed> $attributes
 */
function dl_doc(array $attributes = []): Document
{
    return Document::factory()->create([
        'repository_id' => test()->repo->id,
        'series_id' => test()->series->id,
        // The factory's random type is not one of the active types on the form.
        'document_type' => null,
        ...$attributes,
    ]);
}

function dl_authority(string $code, string $surname): Authority
{
    return Authority::query()->create([
        'identifier' => 'NAM-' . $code,
        'alternative_identifier' => $code,
        'surname' => $surname,
        'given_names' => 'Nicola',
        'entity_type' => 'PERSON',
    ]);
}

function dl_pastReferenceField(int $repositoryId): CustomFieldDefinition
{
    return CustomFieldDefinition::create([
        'repository_id' => $repositoryId,
        'entity_type' => 'document',
        'key' => 'past_reference_code',
        'label' => 'Past Reference Code',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);
}

/* ---- Sections ---- */

it('has the same sections in the same order on New, Edit and View', function (): void {
    $expected = ['Identification', 'Alternate Identification', 'Logistics', 'Disinfestation', 'Supporting Documents'];
    $document = dl_doc();

    $headings = fn ($schema): array => collect($schema->getFlatComponents(withHidden: true))
        ->filter(fn ($component): bool => $component instanceof Section)
        ->map(fn (Section $section): string => (string) $section->getHeading())
        ->intersect($expected)
        ->values()
        ->all();

    $create = Livewire::test(CreateDocument::class)->instance()->form;
    $edit = Livewire::test(EditDocument::class, ['record' => $document->getKey()])->instance()->form;
    $view = Livewire::test(ViewDocument::class, ['record' => $document->getKey()])->instance()->getSchema('infolist');

    expect($headings($create))->toBe($expected)
        ->and($headings($edit))->toBe($expected)
        ->and($headings($view))->toBe($expected);
});

it('shows the sections before the history tabs on the view page', function (): void {
    $html = Livewire::test(ViewDocument::class, ['record' => dl_doc()->getKey()])->html();

    expect(strpos($html, 'Identification'))->toBeLessThan(strpos($html, 'Location history'))
        ->and(strpos($html, 'Supporting Documents'))->toBeLessThan(strpos($html, 'Location history'));
});

it('no longer offers the removed fields', function (string $field): void {
    Livewire::test(EditDocument::class, ['record' => dl_doc()->getKey()])
        ->assertFormFieldDoesNotExist($field);
})->with(['ras_batch_1', 'in_situ_box_1', 'barcode_in', 'barcode_ras_1', 'status_1', 'number_of_acts', 'accession_code_legacy', 'extra', 'custom_fields', 'metadata', 'identifierHistory']);

it('leaves the removed columns as they were when a document is saved', function (): void {
    $document = dl_doc([
        'ras_batch_1' => '4', 'ras_box_1' => '192', 'in_situ_box_1' => 'NRA 5', 'barcode_in' => 'BC1', 'barcode_ras_1' => 'BC2',
        'status_1' => 'IN', 'number_of_acts' => '42', 'accession_code_legacy' => 'OLD', 'custom_fields' => ['a' => 'b'], 'metadata' => ['c' => 'd'],
    ]);
    $document->forceFill(['extra' => ['legacy' => 'kept']])->save();

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['part_number' => '7'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->part_number)->toBe('7')
        ->and($fresh->ras_batch_1)->toBe('4')
        ->and($fresh->ras_box_1)->toBe('192')
        ->and($fresh->in_situ_box_1)->toBe('NRA 5')
        ->and($fresh->barcode_in)->toBe('BC1')
        ->and($fresh->barcode_ras_1)->toBe('BC2')
        ->and($fresh->status_1)->toBe('IN')
        ->and($fresh->number_of_acts)->toBe('42')
        ->and($fresh->accession_code_legacy)->toBe('OLD')
        ->and($fresh->custom_fields)->toBe(['a' => 'b'])
        ->and($fresh->metadata)->toBe(['c' => 'd'])
        ->and($fresh->extra->get('legacy'))->toBe('kept');
});

it('keeps Digitised and its link editable under Supporting Documents', function (): void {
    $document = dl_doc();

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['digitised_url' => 'https://example.org/scan'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->fresh()->digitised_url)->toBe('https://example.org/scan');
});

/* ---- New document ---- */

it('opens a new document with the default repository and custody status, which the column requires', function (): void {
    Livewire::test(CreateDocument::class)
        ->assertFormSet(['repository_id' => $this->repo->id, 'custody_status' => 'in_box'])
        ->fillForm(['series_id' => $this->series->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->custody_status)->toBe('in_box');
});

/* ---- Identifier ---- */

it('assigns the identifier of a new document, in the format of the importer', function (): void {
    $type = DocumentType::query()->create(['name' => 'Bastardello', 'is_active' => true]);

    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => $type->storedValue()])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->identifier)->toMatch('/^NRA-REG-BAS-[0-9A-F]{12}$/');
});

it('does not let a posted identifier through, on New or on Edit', function (): void {
    $document = dl_doc(['identifier' => 'KEEP-ME']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->assertFormFieldIsDisabled('identifier')
        ->set('data.identifier', 'FORGED')
        ->call('save')
        ->assertHasNoFormErrors();
    expect($document->fresh()->identifier)->toBe('KEEP-ME');

    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id])
        ->set('data.identifier', 'FORGED')
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Document::query()->where('identifier', 'FORGED')->exists())->toBeFalse();
});

it('draws another identifier when the first is taken, also by a deleted document', function (): void {
    $taken = 'NRA-REG-' . strtoupper(substr(sha1('first'), 0, 12));
    dl_doc(['identifier' => $taken])->delete();

    Str::createRandomStringsUsingSequence(['first', 'second']);
    $document = Document::factory()->create(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'identifier' => null, 'document_type' => null]);
    Str::createRandomStringsNormally();

    expect($document->identifier)->toBe('NRA-REG-' . strtoupper(substr(sha1('second'), 0, 12)));
});

it('leaves an identifier set by the caller, as the importers do', function (): void {
    expect(dl_doc(['identifier' => 'R12-V3'])->identifier)->toBe('R12-V3');
});

it('no longer offers Change identifier among the power actions', function (): void {
    $names = collect(DocumentActionGroup::singleHeaderActions())->map(fn ($action): ?string => $action->getName());

    expect($names)->not->toContain('updateIdentifier');
});

/* ---- Not-disinfested indicator ---- */

it('shows the disinfestation state in Identification', function (array $attributes, string $expected): void {
    $document = dl_doc($attributes);

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])->assertSee($expected);
    Livewire::test(EditDocument::class, ['record' => $document->getKey()])->assertSee($expected);
})->with([
    'never' => [['disinfestation_date' => null, 'is_in_disinfestation' => false], 'Not disinfested'],
    'in a cycle' => [['disinfestation_date' => '2024-03-22', 'is_in_disinfestation' => true], 'Current Cycle'],
    'done' => [['disinfestation_date' => '2024-03-22', 'is_in_disinfestation' => false], '22-03-2024'],
]);

it('takes the date from the box when the document has none', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'disinfestation_date' => '2023-01-15']);
    $document = dl_doc(['current_box_id' => $box->id, 'disinfestation_date' => null]);

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSee('15-01-2023')
        ->assertDontSee('Not disinfested');
});

it('does not show the indicator on a new document', function (): void {
    Livewire::test(CreateDocument::class)
        ->assertDontSee('Not disinfested')
        ->assertDontSee('Current Cycle');
});

it('shows Current Cycle prominently in the Disinfestation section of the view page', function (): void {
    $notIn = Livewire::test(ViewDocument::class, ['record' => dl_doc(['is_in_disinfestation' => false])->getKey()]);
    $in = Livewire::test(ViewDocument::class, ['record' => dl_doc(['is_in_disinfestation' => true])->getKey()]);

    // Once in Identification and once, large, in Disinfestation.
    expect(substr_count($in->html(), 'Current Cycle'))->toBe(2)
        ->and(substr_count($notIn->html(), 'Current Cycle'))->toBe(0);
});

/* ---- Dates ---- */

it('opens a document on the option its dates call for', function (array $attributes, string $option): void {
    Livewire::test(EditDocument::class, ['record' => dl_doc($attributes)->getKey()])
        ->assertFormSet(['dates_as' => $option]);
})->with([
    'dates set' => [['dates_start' => '1738-01-05', 'dates' => '05-01-1738'], 'date'],
    'nothing' => [['dates' => null], 'year'],
    'years, no text' => [['dates' => null, 'dates_year_start' => 1738, 'dates_year_end' => 1739], 'year'],
    'exact year text' => [['dates' => '1738 - 1739', 'dates_year_start' => 1738, 'dates_year_end' => 1739], 'year'],
    'single year' => [['dates' => '1738', 'dates_year_start' => 1738, 'dates_year_end' => 1738], 'year'],
    'other spelling' => [['dates' => '1738-1739', 'dates_year_start' => 1738, 'dates_year_end' => 1739], 'text'],
    'words' => [['dates' => 'Jun 1997 - Nov 1998', 'dates_year_start' => 1997, 'dates_year_end' => 1998], 'text'],
]);

it('selects no date option on a new document, and asks', function (): void {
    Livewire::test(CreateDocument::class)
        ->assertFormSet(['dates_as' => null])
        ->assertFormFieldIsHidden('dates')
        ->assertFormFieldIsHidden('dates_year_start')
        ->assertFormFieldIsHidden('dates_start');
});

it('shows only the fields of the chosen date option', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['dates_as' => 'year'])
        ->assertFormFieldIsVisible('dates_year_start')
        ->assertFormFieldIsVisible('dates_year_end')
        ->assertFormFieldIsHidden('dates_start')
        ->assertFormFieldIsHidden('dates')
        ->fillForm(['dates_as' => 'date'])
        ->assertFormFieldIsVisible('dates_start')
        ->assertFormFieldIsVisible('dates_end')
        ->assertFormFieldIsHidden('dates_year_start')
        ->fillForm(['dates_as' => 'text'])
        ->assertFormFieldIsVisible('dates')
        ->assertFormFieldIsHidden('dates_start');
});

it('never changes a date text just by opening and saving', function (string $text, ?int $start, ?int $end): void {
    $document = dl_doc(['dates' => $text, 'dates_year_start' => $start, 'dates_year_end' => $end]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->dates)->toBe($text)
        ->and($fresh->dates_year_start)->toBe($start)
        ->and($fresh->dates_year_end)->toBe($end);
})->with([
    ['Jun 1997 - Nov 1998', 1997, 1998],
    ['1738-1739', 1738, 1739],
    ['undated', null, null],
    // The years of an untouched text stay, even when they do not come from it.
    ['circa the plague', 1676, 1676],
    ['1738 - 1739', 1738, 1739],
]);

it('keeps a text alongside dates when the document is only opened and saved', function (): void {
    $document = dl_doc(['dates' => 'early Jan 1738', 'dates_start' => '1738-01-05', 'dates_year_start' => 1738]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->fresh()->dates)->toBe('early Jan 1738')
        ->and($document->fresh()->dates_start->toDateString())->toBe('1738-01-05');
});

it('writes the year text and clears the dates with the Year option', function (): void {
    $document = dl_doc(['dates' => 'something', 'dates_start' => '1700-01-01', 'dates_end' => '1700-02-01']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['dates_as' => 'year', 'dates_year_start' => 1738, 'dates_year_end' => 1739])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->dates)->toBe('1738 - 1739')
        ->and($fresh->dates_year_start)->toBe(1738)
        ->and($fresh->dates_start)->toBeNull()
        ->and($fresh->dates_end)->toBeNull();
});

it('writes a single year on its own', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'dates_as' => 'year', 'dates_year_start' => 1738])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->dates)->toBe('1738');
});

it('refuses a year end before the year start', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'dates_as' => 'year', 'dates_year_start' => 1739, 'dates_year_end' => 1738])
        ->call('create')
        ->assertHasFormErrors(['dates_year_end']);
});

it('writes the dd-mm-yyyy text with the Date option, and the years follow for the filters', function (): void {
    $document = dl_doc(['dates' => '1600', 'dates_year_start' => 1600, 'dates_year_end' => 1600]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['dates_as' => 'date', 'dates_start' => '1738-01-05', 'dates_end' => '1739-02-06'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->dates)->toBe('05-01-1738 - 06-02-1739')
        ->and($fresh->dates_year_start)->toBe(1738)
        ->and($fresh->dates_year_end)->toBe(1739);
});

it('fills the years from a changed free text, and clears the dates', function (string $text, ?int $start, ?int $end): void {
    $document = dl_doc(['dates' => '1600', 'dates_year_start' => 1600, 'dates_year_end' => 1600, 'dates_start' => '1600-01-01']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['dates_as' => 'text', 'dates' => $text])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->dates)->toBe($text)
        ->and($fresh->dates_year_start)->toBe($start)
        ->and($fresh->dates_year_end)->toBe($end)
        ->and($fresh->dates_start)->toBeNull();
})->with([
    ['Jun 1997 - Nov 1998', 1997, 1998],
    ['undated', null, null],
]);

it('saves the dates of a new document with no option chosen as none', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id])
        ->set('data.dates', 'forged')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->dates)->toBeNull();
});

/* ---- Multi-value fields ---- */

it('stores multi-value fields joined by "; " in their own column', function (): void {
    $document = dl_doc();

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm([
            'object_reference_number' => ['OBJ-1', 'OBJ-2'],
            'citation_reference' => ['CIT-1', 'CIT-2'],
            'museum_reference' => ['MUS-1', 'MUS-2'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $document->fresh();
    expect($fresh->object_reference_number)->toBe('OBJ-1; OBJ-2')
        ->and($fresh->citation_reference)->toBe('CIT-1; CIT-2')
        ->and($fresh->museum_reference)->toBe('MUS-1; MUS-2');
});

it('reads a stored value back as separate tags, and a cleared field as nothing', function (): void {
    $document = dl_doc(['object_reference_number' => 'OBJ-1;OBJ-2 ; OBJ-3', 'museum_reference' => 'MUS-1']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->assertFormSet(['object_reference_number' => ['OBJ-1', 'OBJ-2', 'OBJ-3'], 'museum_reference' => ['MUS-1']])
        ->fillForm(['museum_reference' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->fresh()->object_reference_number)->toBe('OBJ-1; OBJ-2; OBJ-3')
        ->and($document->fresh()->museum_reference)->toBeNull();
});

it('refuses more than a column may hold, with a message', function (): void {
    Livewire::test(EditDocument::class, ['record' => dl_doc()->getKey()])
        ->fillForm(['museum_reference' => [str_repeat('a', 300), str_repeat('b', 300)]])
        ->call('save')
        ->assertHasFormErrors(['museum_reference']);
});

it('lists each value on the view page, each conservation number with its own link', function (): void {
    $document = dl_doc(['object_reference_number' => 'OBJ-1; OBJ-2', 'citation_reference' => 'CIT-1; CIT-2']);

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSeeHtml(Document::driveSearchUrl('OBJ-1'))
        ->assertSeeHtml(Document::driveSearchUrl('OBJ-2'))
        ->assertSee('CIT-1')
        ->assertSee('CIT-2');
});

it('edits the Past Reference Code under Alternate Identification, once, keeping the other custom fields', function (): void {
    dl_pastReferenceField($this->repo->id);
    CustomFieldDefinition::create([
        'repository_id' => $this->repo->id, 'entity_type' => 'document', 'key' => 'other_field', 'label' => 'Other Field',
        'type' => 'text', 'is_required' => false, 'is_active' => true, 'sort_order' => 1,
    ]);
    $document = dl_doc();
    $document->setCustomFieldData(['past_reference_code' => 'P1;P2', 'other_field' => 'kept']);

    $page = Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->assertFormSet(['custom.past_reference_code' => ['P1', 'P2']]);
    // A tag input, not the plain text input the Custom fields section would make.
    expect(substr_count($page->html(), 'Past Reference Code'))->toBe(1);

    $page->fillForm(['custom.past_reference_code' => ['P1', 'P2', 'P3']])
        ->call('save')
        ->assertHasNoFormErrors();

    $values = $document->fresh()->getCustomFieldData();
    expect($values['past_reference_code'])->toBe('P1; P2; P3')
        ->and($values['other_field'])->toBe('kept');

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSee('P3')
        ->assertSee('Other Field');
});

it('offers no Past Reference Code where the repository has no such field', function (): void {
    Livewire::test(EditDocument::class, ['record' => dl_doc()->getKey()])
        ->assertFormFieldDoesNotExist('custom.past_reference_code');
});

it('offers the Past Reference Code as tags once a repository with the field is picked', function (): void {
    $other = Repository::factory()->create();
    dl_pastReferenceField($other->id);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldDoesNotExist('custom.past_reference_code')
        ->fillForm(['repository_id' => $other->id])
        ->assertFormFieldExists('custom.past_reference_code')
        ->assertFormSet(['custom.past_reference_code' => []])
        ->fillForm(['series_id' => $this->series->id, 'custom.past_reference_code' => ['P1', 'P2']])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->getCustomFieldData()['past_reference_code'])->toBe('P1; P2');
});

/* ---- Past volumes and authorities ---- */

it('keeps the old volume when the volume changes (her image 3)', function (): void {
    $document = dl_doc(['volume_number' => '5']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['volume_number' => '4'])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = $document->pastVolumes()->sole();
    expect($row->previous_volume)->toBe('5')
        ->and($row->new_volume)->toBe('4')
        ->and($row->repository_id)->toBe($this->repo->id);
});

it('records nothing when the volume stays, or when there was none', function (): void {
    $same = dl_doc(['volume_number' => '5']);
    Livewire::test(EditDocument::class, ['record' => $same->getKey()])->call('save');
    expect($same->identifierHistory()->count())->toBe(0);

    $none = dl_doc(['volume_number' => null]);
    Livewire::test(EditDocument::class, ['record' => $none->getKey()])
        ->fillForm(['volume_number' => '1'])
        ->call('save');
    expect($none->identifierHistory()->count())->toBe(0);
});

it('keeps the old authority when one is removed (her image 4)', function (): void {
    $wrong = dl_authority('R16', 'Dos');
    $right = dl_authority('R12', 'Abela');
    $document = dl_doc();
    $document->authorities()->attach($wrong->id, ['is_primary' => true]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['authorities' => [$right->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->fresh()->authorities->modelKeys())->toBe([$right->id])
        ->and($document->pastAuthorities()->sole()->previous_authority_id)->toBe($wrong->id);
});

it('does not duplicate a past volume or authority typed by hand in the same save', function (): void {
    $wrong = dl_authority('R16', 'Dos');
    $document = dl_doc(['volume_number' => '5']);
    $document->authorities()->attach($wrong->id);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm([
            'volume_number' => '4',
            'authorities' => [],
            'pastVolumes' => [['previous_volume' => '5', 'reason' => 'typed', 'changed_at' => '2026-01-02']],
            'pastAuthorities' => [['previous_authority_id' => $wrong->id, 'reason' => 'typed', 'changed_at' => '2026-01-02']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $volume = $document->pastVolumes()->sole();
    $authority = $document->pastAuthorities()->sole();
    expect($volume->reason)->toBe('typed')
        ->and($volume->previous_identifier)->toBe($document->identifier)
        ->and($volume->repository_id)->toBe($this->repo->id)
        ->and($volume->changed_at->toDateString())->toBe('2026-01-02')
        ->and($authority->reason)->toBe('typed');
});

it('records nothing automatically on create', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'volume_number' => '5'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DocumentIdentifierHistory::query()->count())->toBe(0);
});

it('takes a past volume typed on a new document', function (): void {
    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'pastVolumes' => [['previous_volume' => '9']]])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = Document::query()->sole();
    $row = $document->pastVolumes()->sole();
    expect($row->previous_identifier)->toBe($document->identifier)
        ->and($row->repository_id)->toBe($this->repo->id);
});

it('does not rewrite the time of a history row whose day did not change', function (): void {
    $document = dl_doc();
    $row = DocumentIdentifierHistory::recordPastVolume($document, '3', '2');
    $row->forceFill(['changed_at' => '2025-05-05 13:14:15'])->save();

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])->call('save')->assertHasNoFormErrors();

    expect($row->fresh()->changed_at->format('Y-m-d H:i:s'))->toBe('2025-05-05 13:14:15');
});

it('hides the past rows from a user who may not update documents', function (): void {
    $creator = Role::findOrCreate('creator_only', 'web');
    $creator->givePermissionTo(Permission::findOrCreate('create_document', 'web'), Permission::findOrCreate('view_any_document', 'web'));
    $user = bl_actor('editor', $this->repo);
    $user->syncRoles(['creator_only']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user->fresh());

    Livewire::test(CreateDocument::class)
        ->assertFormFieldIsHidden('pastVolumes')
        ->assertFormFieldIsHidden('pastAuthorities');
});

it('shows past volumes and past authorities on the view page, each authority with its link', function (): void {
    $past = dl_authority('R16', 'Dos');
    $document = dl_doc();
    DocumentIdentifierHistory::recordPastVolume($document, '5', '4', 'misread spine');
    DocumentIdentifierHistory::recordPastAuthority($document, $past->id, 'wrongly attributed');

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSee('misread spine')
        ->assertSee($past->citingLabel())
        ->assertSee('wrongly attributed')
        ->assertSeeHtml(route('filament.admin.resources.authorities.view', ['record' => $past->id]));
});

it('still shows an imported previous attribution, read-only', function (): void {
    $document = dl_doc(['identifier' => 'NOW']);
    DocumentIdentifierHistory::create([
        'document_id' => $document->id, 'previous_identifier' => 'R999', 'new_identifier' => 'NOW', 'changed_at' => now(),
        'reason' => 'Imported — previously attributed', 'repository_id' => $this->repo->id,
    ]);

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])->assertSee('R999');
    Livewire::test(EditDocument::class, ['record' => $document->getKey()])->assertSee('R999');
});

/* ---- View: authorities, box, accession ---- */

it('links each authority by its citing label on the view page, the primary one first', function (): void {
    $other = dl_authority('R1', 'Aaa');
    $primary = dl_authority('R2', 'Zzz');
    $document = dl_doc();
    $document->authorities()->attach([$other->id => ['is_primary' => false], $primary->id => ['is_primary' => true]]);

    $page = Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSee($primary->citingLabel())
        ->assertSee($other->citingLabel())
        ->assertSeeHtml(route('filament.admin.resources.authorities.view', ['record' => $primary->id]))
        ->assertSeeHtml(route('filament.admin.resources.authorities.view', ['record' => $other->id]));

    expect(strpos($page->html(), $primary->citingLabel()))->toBeLessThan(strpos($page->html(), $other->citingLabel()));
});

it('takes the user from the document to the Box view, on the view and edit pages', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => 4]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 192, 'box_type' => 'RAS']);
    $document = dl_doc(['current_box_id' => $box->id]);
    $url = route('filament.admin.resources.boxes.view', ['record' => $box->id]);

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])
        ->assertSee('Batch 4 / Box 192')
        ->assertSeeHtml($url);
    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->assertSeeHtml($url);

    Livewire::test(ViewBox::class, ['record' => $box->getKey()])->assertOk();
});

it('labels the accession by number and title on this field, or the title alone', function (): void {
    $numbered = Accession::query()->create(['code' => 'Notary Tabone Accession', 'accession_number' => 'AC12', 'repository_id' => $this->repo->id]);
    $plain = Accession::query()->create(['code' => 'Notary Vella Accession', 'repository_id' => $this->repo->id]);

    $field = Livewire::test(CreateDocument::class)->instance()->form->getComponent('accession_id');

    expect($field->getOptions())->toBe([
        $numbered->id => 'AC12 Notary Tabone Accession',
        $plain->id => 'Notary Vella Accession',
    ]);

    Livewire::test(ViewDocument::class, ['record' => dl_doc(['accession_id' => $numbered->id])->getKey()])
        ->assertSee('AC12 Notary Tabone Accession');
});

/* ---- Locked placement ---- */

it('does not take a forged box or batch on Edit', function (): void {
    $batchA = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $batchB = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $boxA = Box::factory()->create(['batch_id' => $batchA->id]);
    $boxB = Box::factory()->create(['batch_id' => $batchB->id]);
    $document = dl_doc(['current_box_id' => $boxA->id, 'batch_id' => $batchA->id]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->set('data.current_box_id', $boxB->id)
        ->set('data.batch_id', $batchB->id)
        ->call('save');

    expect($document->fresh()->current_box_id)->toBe($boxA->id)
        ->and($document->fresh()->batch_id)->toBe($batchA->id);
});

it('still takes the box chosen on a new document', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id]);

    Livewire::test(CreateDocument::class)
        ->fillForm(['repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'current_box_id' => $box->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->current_box_id)->toBe($box->id);
});

/* ---- Museum Location ---- */

it('offers the museum locations of the repository by code, keeping a stored value not among them', function (): void {
    $other = Repository::factory()->create();
    Location::factory()->create(['repository_id' => $this->repo->id, 'name' => 'Showcase 1', 'code' => 'MUSE-1-1', 'type' => 'museum']);
    Location::factory()->create(['repository_id' => $this->repo->id, 'name' => 'Archive 1', 'code' => 'REPO-1-1', 'type' => 'repository']);
    Location::factory()->create(['repository_id' => $other->id, 'name' => 'Foreign showcase', 'code' => 'MUSE-2-1', 'type' => 'museum']);
    $document = dl_doc(['museum_location' => 'OLD-CODE']);

    $options = Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->instance()->form->getComponent('museum_location')->getOptions();

    expect(array_keys($options))->toContain('MUSE-1-1', 'OLD-CODE')
        ->and(array_keys($options))->not->toContain('REPO-1-1', 'MUSE-2-1')
        ->and($options['OLD-CODE'])->toBe('OLD-CODE');

    // Opened and saved, the old value stays.
    Livewire::test(EditDocument::class, ['record' => $document->getKey()])->call('save')->assertHasNoFormErrors();
    expect($document->fresh()->museum_location)->toBe('OLD-CODE');
});

it('does not let an editor pick, or forge, the museum location of another repository', function (): void {
    $other = Repository::factory()->create();
    Location::factory()->create(['repository_id' => $other->id, 'name' => 'Foreign showcase', 'code' => 'MUSE-2-1', 'type' => 'museum']);
    Location::factory()->create(['repository_id' => $this->repo->id, 'name' => 'Showcase 1', 'code' => 'MUSE-1-1', 'type' => 'museum']);
    $this->actingAs(bl_actor('editor', $this->repo));
    $document = dl_doc();

    $page = Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        // A posted repository the editor has no access to shows nothing of it.
        ->set('data.repository_id', $other->id);
    expect($page->instance()->form->getComponent('museum_location')->getOptions())->toBe([]);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->fillForm(['museum_location' => 'MUSE-2-1'])
        ->call('save')
        ->assertHasFormErrors(['museum_location']);
    expect($document->fresh()->museum_location)->toBeNull();
});

it('does not write the dates of a role that may not', function (): void {
    config()->set('field_permissions.document.dates', ['read' => ['super_admin', 'admin', 'editor', 'viewer'], 'write' => ['super_admin']]);
    $this->actingAs(bl_actor('editor', $this->repo));
    $document = dl_doc(['dates' => 'Jun 1997 - Nov 1998']);

    Livewire::test(EditDocument::class, ['record' => $document->getKey()])
        ->set('data.dates_as', 'text')
        ->set('data.dates', 'FORGED')
        ->call('save');

    expect($document->fresh()->dates)->toBe('Jun 1997 - Nov 1998');
});
