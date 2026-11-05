<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Repository;
use App\Models\User;
use Filament\Tables\Columns\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/**
 * Client 2026-11-05, issues 7 and 10: "can you check that boxes/Documents pages
 * have all fields with sort function?" Every sort orders by what the cell shows,
 * with data whose order differs from the id order, through the page's sortTable.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->user = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->user);
    $this->batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
});

function ls_box(array $attrs = []): Box
{
    return Box::factory()->create(array_merge(['batch_id' => test()->batch->id], $attrs));
}

function ls_doc(array $attrs = []): Document
{
    return Document::factory()->create(array_merge(['repository_id' => test()->repo->id, 'batch_id' => test()->batch->id], $attrs));
}

function ls_loc(string $name, array $attrs = []): int
{
    return (int) qf_location(test()->repo->id, array_merge(['name' => $name, 'type' => 'repository'], $attrs))->getKey();
}

/** The ids of the table's records, in the order it renders them. */
function ls_order(Testable $page): array
{
    return $page->instance()->getTableRecords()->map(fn ($r): int => (int) $r->getKey())->values()->all();
}

/**
 * @param class-string $list
 * @param list<int> $expected ids ascending
 */
function ls_assertSort(string $list, string $column, array $expected, ?array $descending = null): void
{
    expect(ls_order(Livewire::test($list)->sortTable($column)))->toBe($expected);

    $page = Livewire::test($list)->sortTable($column)->sortTable($column, 'desc');
    expect(ls_order($page))->toBe($descending ?? array_reverse($expected));
}

it('makes every column of the Boxes and Documents lists sortable', function (string $list): void {
    CustomFieldDefinition::query()->create(['repository_id' => $this->repo->id, 'entity_type' => 'box', 'key' => 'cf_b', 'label' => 'CF B', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
    CustomFieldDefinition::query()->create(['repository_id' => $this->repo->id, 'entity_type' => 'document', 'key' => 'cf_d', 'label' => 'CF D', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);

    $unsortable = collect(Livewire::test($list)->instance()->getTable()->getColumns())
        ->reject(fn (Column $column): bool => $column->isSortable())
        ->map(fn (Column $column): string => $column->getName())
        ->values()
        ->all();

    expect($unsortable)->toBe([]);
})->with([
    'boxes' => [ListBoxes::class],
    'documents' => [ListDocuments::class],
]);

/* ---- Boxes ---------------------------------------------------------------- */

it('sorts boxes by location: own, else shared by documents, natural order, blanks last', function (): void {
    $a2 = ls_loc('Archive 2');
    $a10 = ls_loc('Archive 10');
    $cat = ls_loc('Cataloguing', ['type' => 'room']);

    $own10 = ls_box(['location_id' => $a10]);
    $none = ls_box();
    $fromDocs = ls_box();
    ls_doc(['current_box_id' => $fromDocs->id, 'location_id' => $a2]);
    $disagree = ls_box();
    ls_doc(['current_box_id' => $disagree->id, 'location_id' => $a2]);
    ls_doc(['current_box_id' => $disagree->id, 'location_id' => $cat]);
    $ownCat = ls_box(['location_id' => $cat]);

    expect($fromDocs->fresh()->sharedDocumentLocation()?->id)->toBe($a2);

    // Asserted through the page rather than computed: blanks stay last both ways.
    $asc = ls_order(Livewire::test(ListBoxes::class)->sortTable('location.full_path'));
    expect(array_slice($asc, 0, 3))->toBe([$fromDocs->id, $own10->id, $ownCat->id])
        ->and(array_slice($asc, 3))->toEqualCanonicalizing([$none->id, $disagree->id]);

    $desc = ls_order(Livewire::test(ListBoxes::class)->sortTable('location.full_path')->sortTable('location.full_path', 'desc'));
    expect(array_slice($desc, 0, 3))->toBe([$ownCat->id, $own10->id, $fromDocs->id])
        ->and(array_slice($desc, 3))->toEqualCanonicalizing([$none->id, $disagree->id]);
});

it('sorts boxes by Tracking Note, Seal Number and Notes, blanks last', function (string $column, string $attr): void {
    $b = ls_box([$attr => 'bravo']);
    $none = ls_box([$attr => null]);
    $a = ls_box([$attr => 'alpha']);
    $empty = ls_box([$attr => $attr === 'seal_number' ? null : '']);

    $asc = ls_order(Livewire::test(ListBoxes::class)->sortTable($column));
    expect(array_slice($asc, 0, 2))->toBe([$a->id, $b->id])
        ->and(array_slice($asc, 2))->toEqualCanonicalizing([$none->id, $empty->id]);

    $desc = ls_order(Livewire::test(ListBoxes::class)->sortTable($column)->sortTable($column, 'desc'));
    expect(array_slice($desc, 0, 2))->toBe([$b->id, $a->id]);
})->with([
    ['tracking_note', 'tracking_note'],
    ['seal_number', 'seal_number'],
    ['notes', 'notes'],
]);

it('sorts boxes by Batch in number order', function (): void {
    $ten = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '10']);
    $two = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '2']);
    $b10 = ls_box(['batch_id' => $ten->id]);
    $b2 = ls_box(['batch_id' => $two->id]);
    $this->batch->delete();

    ls_assertSort(ListBoxes::class, 'batch.batch_number', [$b2->id, $b10->id]);
});

it('sorts boxes by Barcode Status as shown: no barcode is an empty cell', function (): void {
    $out = ls_box(['barcode_status' => 'OUT']);
    $noBarcode = ls_box(['barcode' => null, 'barcode_status' => 'IN']);
    $in = ls_box(['barcode_status' => 'IN']);

    ls_assertSort(ListBoxes::class, 'barcode_status', [$in->id, $out->id, $noBarcode->id], [$out->id, $in->id, $noBarcode->id]);
});

it('sorts boxes by the parent as the cell names it', function (): void {
    $p9 = ls_box(['box_number' => '9']);
    $p3 = ls_box(['box_number' => '3']);
    $child9 = Box::factory()->create(['box_type' => 'NRA', 'box_number' => 'N1', 'batch_id' => null, 'repository_id' => $this->repo->id, 'parent_box_id' => $p9->id]);
    $child3 = Box::factory()->create(['box_type' => 'NRA', 'box_number' => 'N2', 'batch_id' => null, 'repository_id' => $this->repo->id, 'parent_box_id' => $p3->id]);

    $asc = ls_order(Livewire::test(ListBoxes::class)->sortTable('parent_box_id'));
    expect(array_slice($asc, 0, 2))->toBe([$child3->id, $child9->id]);
});

it('sorts by Inputter: created audit first, then updated, then any; blanks last', function (): void {
    $zed = User::factory()->create(['name' => 'Zed']);
    $amy = User::factory()->create(['name' => 'Amy']);
    $mia = User::factory()->create(['name' => 'Mia']);

    DB::table('audits')->delete();
    $one = ls_box();
    $two = ls_box();
    $three = ls_box();
    $four = ls_box();
    DB::table('audits')->delete();

    $audit = function (Box $box, string $event, User $user): void {
        Audit::query()->create(['event' => $event, 'auditable_type' => $box->getMorphClass(), 'auditable_id' => $box->id, 'user_type' => User::class, 'user_id' => $user->id, 'old_values' => [], 'new_values' => []]);
    };
    // An earlier 'updated' by Amy must not beat the 'created' by Zed.
    $audit($one, 'updated', $amy);
    $audit($one, 'created', $zed);
    $audit($two, 'updated', $mia);
    $audit($three, 'deleted', $amy);
    // $four has no audit: blank.

    ls_assertSort(ListBoxes::class, 'inputter', [$three->id, $two->id, $one->id, $four->id], [$one->id, $two->id, $three->id, $four->id]);
});

it('sorts by a custom field, numbers as numbers, on either list', function (): void {
    $def = CustomFieldDefinition::query()->create(['repository_id' => $this->repo->id, 'entity_type' => 'box', 'key' => 'weight', 'label' => 'Weight', 'type' => 'number', 'is_active' => true, 'sort_order' => 1]);
    $ten = ls_box();
    $blank = ls_box();
    $two = ls_box();
    foreach ([[$ten, '10'], [$two, '2']] as [$box, $value]) {
        CustomFieldValue::query()->create(['custom_field_definition_id' => $def->id, 'customizable_type' => $box->getMorphClass(), 'customizable_id' => $box->id, 'value' => $value]);
    }

    ls_assertSort(ListBoxes::class, 'customFieldValues_weight', [$two->id, $ten->id, $blank->id], [$ten->id, $two->id, $blank->id]);
});

/* ---- Documents ------------------------------------------------------------ */

it('sorts documents by location: own, else its box', function (): void {
    $a2 = ls_loc('Archive 2');
    $a10 = ls_loc('Archive 10');
    $box = ls_box(['location_id' => $a2, 'barcode_status' => 'OUT']);

    $own10 = ls_doc(['location_id' => $a10, 'identifier' => 'D1']);
    $none = ls_doc(['identifier' => 'D2']);
    $viaBox = ls_doc(['current_box_id' => $box->id, 'identifier' => 'D3']);

    ls_assertSort(ListDocuments::class, 'effective_location', [$viaBox->id, $own10->id, $none->id], [$own10->id, $viaBox->id, $none->id]);
});

it('sorts documents by Disinfested Date: effective, Current Cycle grouped, blanks last', function (): void {
    $box = ls_box(['barcode_status' => 'OUT', 'disinfestation_date' => '2021-06-01']);

    $late = ls_doc(['disinfestation_date' => '2024-01-01']);
    $blank = ls_doc();
    $cycleA = ls_doc(['is_in_disinfestation' => true, 'disinfestation_date' => '2000-01-01']);
    $viaBox = ls_doc(['current_box_id' => $box->id]);
    $early = ls_doc(['disinfestation_date' => '2019-01-01']);
    $cycleB = ls_doc(['is_in_disinfestation' => true]);

    $asc = ls_order(Livewire::test(ListDocuments::class)->sortTable('disinfestation_date'));
    expect(array_slice($asc, 0, 3))->toBe([$early->id, $viaBox->id, $late->id])
        ->and(array_slice($asc, 3, 2))->toEqualCanonicalizing([$cycleA->id, $cycleB->id])
        ->and($asc[5])->toBe($blank->id);

    $desc = ls_order(Livewire::test(ListDocuments::class)->sortTable('disinfestation_date')->sortTable('disinfestation_date', 'desc'));
    expect(array_slice($desc, 0, 2))->toEqualCanonicalizing([$cycleA->id, $cycleB->id])
        ->and(array_slice($desc, 2))->toBe([$late->id, $viaBox->id, $early->id, $blank->id]);
});

it('sorts documents by authority columns: code, alternate code, creator as the cell reads', function (): void {
    $auth = fn (array $a): Authority => Authority::create($a + ['identifier' => 'NAM-' . $a['alternative_identifier'], 'entity_type' => 'PERSON']);
    $zeta = $auth(['alternative_identifier' => 'R9', 'alternative_identifier_warrant' => 'M1', 'given_names' => 'Anna', 'surname' => 'Zeta']);
    $alpha = $auth(['alternative_identifier' => 'R1', 'alternative_identifier_warrant' => 'M9', 'given_names' => 'Zoe', 'surname' => 'Alpha']);
    $surnameOnly = $auth(['alternative_identifier' => 'R5', 'alternative_identifier_warrant' => 'M5', 'given_names' => null, 'surname' => 'Borg']);

    $dZeta = ls_doc();
    $dZeta->authorities()->attach($zeta);
    $dNone = ls_doc();
    $dAlpha = ls_doc();
    $dAlpha->authorities()->attach($alpha);
    $dBorg = ls_doc();
    $dBorg->authorities()->attach($surnameOnly);

    ls_assertSort(ListDocuments::class, 'authorities.alternative_identifier', [$dAlpha->id, $dBorg->id, $dZeta->id, $dNone->id], [$dZeta->id, $dBorg->id, $dAlpha->id, $dNone->id]);
    ls_assertSort(ListDocuments::class, 'authorities.alternative_identifier_warrant', [$dZeta->id, $dBorg->id, $dAlpha->id, $dNone->id], [$dAlpha->id, $dBorg->id, $dZeta->id, $dNone->id]);
    ls_assertSort(ListDocuments::class, 'creator_names', [$dZeta->id, $dBorg->id, $dAlpha->id, $dNone->id], [$dAlpha->id, $dBorg->id, $dZeta->id, $dNone->id]);
});

it('sorts documents by the Prev Attributed columns, from the same history row the cell reads', function (): void {
    $history = fn (Document $d, string $identifier, string $volume) => $d->identifierHistory()->create([
        'previous_identifier' => $identifier,
        'previous_volume' => $volume,
        'reason' => 'Imported — previously attributed',
        'changed_at' => now(),
        'repository_id' => $this->repo->id,
    ]);
    $dB = ls_doc(['identifier' => 'NOW-1']);
    $history($dB, 'BBB', '1');
    $dNone = ls_doc(['identifier' => 'NOW-2']);
    $dA = ls_doc(['identifier' => 'NOW-3']);
    $history($dA, 'AAA', '2');

    ls_assertSort(ListDocuments::class, 'prev_attributed_identifier', [$dA->id, $dB->id, $dNone->id], [$dB->id, $dA->id, $dNone->id]);
    ls_assertSort(ListDocuments::class, 'prev_attributed_volume', [$dB->id, $dA->id, $dNone->id], [$dA->id, $dB->id, $dNone->id]);
});

it('sorts documents by Digitised, Accession and Museum Location as the cells show them', function (): void {
    $viaUrl = ls_doc(['digitised' => 'VHMML', 'digitised_url' => 'https://a.example/1', 'accession_code_legacy' => 'Zed', 'museum_location' => 'CODE-Z']);
    $plain = ls_doc(['digitised' => 'none', 'digitised_url' => null, 'accession_code_legacy' => 'Amy', 'museum_location' => 'UNKNOWN']);
    Location::withoutGlobalScopes()->create(['name' => 'Showcase 1', 'code' => 'CODE-Z', 'type' => 'showcase', 'repository_id' => $this->repo->id, 'is_active' => true]);

    // Cells: "https://a.example/1" < "none"; "Amy" < "Zed"; "Showcase 1" < "UNKNOWN".
    ls_assertSort(ListDocuments::class, 'digitised', [$viaUrl->id, $plain->id]);
    ls_assertSort(ListDocuments::class, 'accession_code_legacy', [$plain->id, $viaUrl->id]);
    ls_assertSort(ListDocuments::class, 'museum_location', [$viaUrl->id, $plain->id]);
});

it('sorts boxes by Box as batch number then box number, in number order', function (): void {
    $ten = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '10']);
    $two = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '2']);
    $b10 = ls_box(['batch_id' => $ten->id, 'box_number' => '1']);
    $b2n10 = ls_box(['batch_id' => $two->id, 'box_number' => '10']);
    $b2n3 = ls_box(['batch_id' => $two->id, 'box_number' => '3']);

    ls_assertSort(ListBoxes::class, 'box_number', [$b2n3->id, $b2n10->id, $b10->id]);
});

it('does not duplicate a document with two authorities, or break the pager', function (): void {
    $a = Authority::create(['identifier' => 'NAM-1', 'alternative_identifier' => 'R1', 'surname' => 'A', 'entity_type' => 'PERSON']);
    $b = Authority::create(['identifier' => 'NAM-2', 'alternative_identifier' => 'R2', 'surname' => 'B', 'entity_type' => 'PERSON']);
    $doc = ls_doc();
    $doc->authorities()->attach([$a->id, $b->id]);
    ls_doc();

    $page = Livewire::test(ListDocuments::class)->sortTable('authorities.alternative_identifier');

    expect($page->instance()->getTableRecords()->total())->toBe(2)
        ->and(ls_order($page))->toHaveCount(2);
});

it('sorts documents by open flags, by name; a resolved flag does not count', function (): void {
    $flag = fn (Document $d, string $type, string $status = 'open'): DocumentFlag => DocumentFlag::factory()->create(['document_id' => $d->id, 'repository_id' => $this->repo->id, 'type' => $type, 'status' => $status]);

    $types = array_keys(DocumentFlag::typeOptions());
    $labels = DocumentFlag::typeOptions();
    natcasesort($labels);
    [$first, $second] = array_keys($labels);
    expect(count($types))->toBeGreaterThan(1);

    $dSecond = ls_doc();
    $flag($dSecond, $second);
    $dNone = ls_doc();
    $flag($dNone, $first, 'resolved');
    $dFirst = ls_doc();
    $flag($dFirst, $first);

    ls_assertSort(ListDocuments::class, 'open_flags_list', [$dFirst->id, $dSecond->id, $dNone->id], [$dSecond->id, $dFirst->id, $dNone->id]);
});

it('sorts documents by the type name the cell shows, not the code', function (): void {
    $z = DocumentType::query()->create(['code' => 'DT1', 'name' => 'Zeta', 'repository_id' => $this->repo->id]);
    $a = DocumentType::query()->create(['code' => 'DT2', 'name' => 'Alpha', 'repository_id' => $this->repo->id]);

    $dZ = ls_doc(['document_type' => 'DT1', 'document_type_id' => $z->id]);
    $dA = ls_doc(['document_type' => 'DT2', 'document_type_id' => $a->id]);

    ls_assertSort(ListDocuments::class, 'document_type', [$dA->id, $dZ->id]);
});

it('sorts documents by Inputter and by a custom field', function (): void {
    $def = CustomFieldDefinition::query()->create(['repository_id' => $this->repo->id, 'entity_type' => 'document', 'key' => 'past_reference_code', 'label' => 'Past Reference Code', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
    $zed = User::factory()->create(['name' => 'Zed']);
    $amy = User::factory()->create(['name' => 'Amy']);
    $d1 = ls_doc();
    $d2 = ls_doc();
    DB::table('audits')->delete();
    Audit::query()->create(['event' => 'created', 'auditable_type' => $d1->getMorphClass(), 'auditable_id' => $d1->id, 'user_type' => User::class, 'user_id' => $zed->id, 'old_values' => [], 'new_values' => []]);
    Audit::query()->create(['event' => 'created', 'auditable_type' => $d2->getMorphClass(), 'auditable_id' => $d2->id, 'user_type' => User::class, 'user_id' => $amy->id, 'old_values' => [], 'new_values' => []]);
    foreach ([[$d1, 'A'], [$d2, 'B']] as [$doc, $value]) {
        CustomFieldValue::query()->create(['custom_field_definition_id' => $def->id, 'customizable_type' => $doc->getMorphClass(), 'customizable_id' => $doc->id, 'value' => $value]);
    }

    ls_assertSort(ListDocuments::class, 'inputter', [$d2->id, $d1->id]);
    ls_assertSort(ListDocuments::class, 'customFieldValues_past_reference_code', [$d1->id, $d2->id]);
});

it('keeps a filter rule working together with the new sorts', function (): void {
    $a2 = ls_loc('Archive 2');
    $a10 = ls_loc('Archive 10');
    $keepA = ls_doc(['location_id' => $a10, 'practice' => 'KEEP']);
    ls_doc(['location_id' => $a2, 'practice' => 'DROP']);
    $keepB = ls_doc(['location_id' => $a2, 'practice' => 'KEEP']);

    $page = Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', doc_rule('practice', 'equals', ['text' => 'KEEP']))
        ->sortTable('effective_location');

    expect(ls_order($page))->toBe([$keepB->id, $keepA->id])
        ->and($page->instance()->getTableRecords()->total())->toBe(2);
});

it('keeps a Box rule working together with the location sort', function (): void {
    $a2 = ls_loc('Archive 2');
    $a10 = ls_loc('Archive 10');
    $keepA = ls_box(['location_id' => $a10, 'barcode_status' => 'OUT']);
    ls_box(['location_id' => $a2, 'barcode_status' => 'IN']);
    $keepB = ls_box(['location_id' => $a2, 'barcode_status' => 'OUT']);

    $page = Livewire::test(ListBoxes::class)
        ->filterTable('queryBuilder', doc_rule('barcode_status', 'is', ['values' => ['OUT']]))
        ->sortTable('location.full_path');

    expect(ls_order($page))->toBe([$keepB->id, $keepA->id]);
});

it('shows only what a non-admin may see when sorting: another tenant ranks nowhere', function (): void {
    $other = Repository::factory()->create();
    $foreignLoc = qf_location($other->id, ['name' => 'AAA foreign']);
    $mine = ls_loc('Archive 1');
    $own = ls_box(['location_id' => $mine]);
    $orphaned = ls_box();
    DB::table('boxes')->where('id', $orphaned->id)->update(['location_id' => $foreignLoc->id]);
    $otherBatch = Batch::withoutGlobalScopes()->create(['repository_id' => $other->id, 'batch_number' => '3', 'is_active' => true]);
    $foreignBox = Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $otherBatch->id, 'barcode_status' => 'IN']);

    $this->actingAs(bl_actor('editor', $this->repo));

    $order = ls_order(Livewire::test(ListBoxes::class)->sortTable('location.full_path'));

    expect($order)->toBe([$own->id, $orphaned->id])
        ->and($order)->not->toContain($foreignBox->id);
});

it('runs each sort without a query per row', function (): void {
    foreach (range(1, 12) as $i) {
        $box = ls_box(['location_id' => ls_loc('Archive ' . $i)]);
        ls_doc(['current_box_id' => $box->id]);
    }
    $queries = function (Closure $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $plain = $queries(fn () => Livewire::test(ListDocuments::class));
    $sorted = $queries(fn () => Livewire::test(ListDocuments::class)->sortTable('effective_location'));
    $plainTwice = $queries(function (): void {
        Livewire::test(ListDocuments::class)->call('$refresh');
    });

    // sortTable is one more request; what the sort itself adds per request is a
    // constant (the location list), not one query for each of the 12 rows.
    expect($sorted - $plainTwice)->toBeLessThanOrEqual(4)
        ->and($plain)->toBeGreaterThan(0);
});

/* ---- Issue 10: Current Cycle ---------------------------------------------- */

it('shows the badge Current Cycle instead of a date while in disinfestation (issue 10)', function (): void {
    $doc = ls_doc(['is_in_disinfestation' => true, 'disinfestation_date' => '2020-05-17']);
    $dated = ls_doc(['disinfestation_date' => '2021-03-04']);

    $page = Livewire::test(ListDocuments::class)
        ->assertTableColumnStateSet('disinfestation_date', 'Current Cycle', $doc)
        ->assertTableColumnStateSet('disinfestation_date', '04-03-2021', $dated);
    $column = $page->instance()->getTable()->getColumn('disinfestation_date');

    expect($column->record($doc)->isBadge())->toBeTrue()
        ->and($column->record($dated)->isBadge())->toBeFalse();
});
