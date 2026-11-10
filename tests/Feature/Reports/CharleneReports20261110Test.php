<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Reports_Operations.docx, client 2026-11-10:
 *   5. Documents by Batch — "sort by batch number in sequence? 1, 2, 3…"
 *   6. Documents by Creator — the Actual Authority Reference Code instead of
 *      the code, the Authorised Form of Name instead of surname and name.
 *   7. Documents by Series/Subseries — a parent series totals its
 *      subseries; level of description and hierarchy shown.
 *   9. Box Movement History renamed Document movement history, with
 *      catalogue identifier, authority code, volume, creator, dates and the
 *      boxes as the archive names them.
 *  12. Flags by type — # Open / # Resolved count documents.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $this->repo));
});

function cr10_batch(string $number): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => $number, 'repository_id' => test()->repo->id],
        ['type' => 'MAIN_COLLECTION', 'is_active' => true],
    );
}

function cr10_doc(array $attrs = []): Document
{
    $attrs['series_id'] ??= Series::firstOrCreate(['code' => 'ZZ'], ['title' => 'Default', 'is_active' => true])->id;

    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'CR10-' . strtoupper(substr(uniqid('', true), -8)),
        'document_type' => 'TEST',
        'repository_id' => test()->repo->id,
    ], $attrs));
}

function cr10_rows(object $page, string $method = 'collectRows'): array
{
    return (new ReflectionMethod($page, $method))->invoke($page);
}

it('lists batches in number order, 2 before 10, documents without a batch last', function (): void {
    foreach (['10', 'Unknown', '2', '1', '30'] as $n) {
        cr10_doc(['batch_id' => cr10_batch($n)->id]);
    }
    cr10_doc(['batch_id' => cr10_batch('10')->id]);
    cr10_doc();

    $rows = cr10_rows(new DocumentsByBatchReport);

    expect(array_column($rows, 0))->toBe(['1', '2', '10', '30', 'Unknown', '(unassigned)']);

    Livewire::test(DocumentsByBatchReport::class)
        ->assertOk()
        ->assertSeeInOrder(['>1<', '>2<', '>10<', '>30<'], false);
});

it('shows the Actual Authority Reference Code and the Authorised Form of Name per creator', function (): void {
    $placido = Authority::create(['identifier' => 'MT AF-P000058', 'alternative_identifier' => 'R4', 'surname' => 'Abela', 'given_names' => 'Placido', 'authorised_form_of_name' => 'Abela, Placido, 1557-1595', 'entity_type' => 'PERSON']);
    $plain = Authority::create(['identifier' => null, 'alternative_identifier' => 'R12', 'surname' => 'Borg', 'given_names' => 'Gio', 'entity_type' => 'PERSON']);
    // A saved form is generated from the name when blank: clear it to test the fallback.
    Authority::query()->whereKey($plain->id)->update(['authorised_form_of_name' => null]);
    cr10_doc()->authorities()->attach([$placido->id, $plain->id]);

    $rows = collect(cr10_rows(new DocumentsByCreatorReport))->keyBy(0);

    expect($rows['R4'])->toBe(['R4', 'Abela, Placido, 1557-1595', 1])
        ->and($rows['R12'])->toBe(['R12', 'Borg, Gio', 1]);

    Livewire::test(DocumentsByCreatorReport::class)
        ->assertOk()
        ->assertSee('Actual Authority Reference Code')
        ->assertSee('Authorised form of name')
        ->assertSee('Abela, Placido, 1557-1595')
        ->assertDontSee('MT AF-P000058');
});

it('totals a parent series over its subseries and lists the parent above them', function (): void {
    $r = Series::create(['code' => 'R', 'title' => 'Register Copies', 'level_of_description' => 'Series', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $reg = Series::create(['code' => 'REG', 'title' => 'Registers', 'level_of_description' => 'SubSeries', 'parent_id' => $r->id, 'is_active' => true, 'repository_id' => $this->repo->id]);
    $rwl = Series::create(['code' => 'RWL', 'title' => 'Registers Public Wills', 'level_of_description' => 'SubSeries', 'parent_id' => $r->id, 'is_active' => true, 'repository_id' => $this->repo->id]);
    $o = Series::create(['code' => 'O', 'title' => 'Originals', 'level_of_description' => 'Series', 'is_active' => true, 'repository_id' => $this->repo->id]);
    cr10_doc(['series_id' => $r->id]);
    cr10_doc(['series_id' => $reg->id]);
    cr10_doc(['series_id' => $reg->id]);
    cr10_doc(['series_id' => $rwl->id]);
    cr10_doc(['series_id' => $o->id]);

    $rows = cr10_rows(new DocumentsBySeriesReport);

    // [Code, Title, Level of description, Hierarchy, # Documents], parents first.
    expect($rows)->toBe([
        ['O', 'Originals', 'Series', 'O', 1],
        ['R', 'Register Copies', 'Series', 'R', 4],
        ['REG', 'Registers', 'SubSeries', 'R › REG', 2],
        ['RWL', 'Registers Public Wills', 'SubSeries', 'R › RWL', 1],
    ]);

    Livewire::test(DocumentsBySeriesReport::class)
        ->assertOk()
        ->assertSee('Level of description')
        ->assertSee('R › REG');
});

it('keeps the parent total within the document filters', function (): void {
    $r = Series::create(['code' => 'R', 'title' => 'R', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $reg = Series::create(['code' => 'REG', 'title' => 'REG', 'parent_id' => $r->id, 'is_active' => true, 'repository_id' => $this->repo->id]);
    cr10_doc(['series_id' => $reg->id, 'document_type' => 'Will']);
    cr10_doc(['series_id' => $reg->id, 'document_type' => 'Deed']);

    Livewire::test(DocumentsBySeriesReport::class)
        ->filterTable('document_type', ['Will'])
        ->assertOk()
        ->assertCountTableRecords(2); // R and REG, one document each
});

it('reads a movement as the document and the boxes the archive names', function (): void {
    $batch = cr10_batch('4');
    $from = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '54', 'batch_id' => $batch->id, 'barcode' => 'CR10A', 'barcode_status' => 'IN']);
    $to = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'NRA', 'box_number' => '10', 'repository_id' => $this->repo->id, 'provenance_unknown' => true, 'barcode' => 'CR10B', 'barcode_status' => 'IN']);
    $authority = Authority::create(['alternative_identifier' => 'R175', 'surname' => 'Caxaro', 'given_names' => 'Brandano', 'entity_type' => 'PERSON']);
    $doc = cr10_doc(['catalogue_identifier' => 'R175/3', 'volume_number' => '3', 'dates' => '1500-1510']);
    $doc->authorities()->attach($authority->id);
    BoxMovement::query()->create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'from_box_id' => $from->id, 'to_box_id' => $to->id, 'movement_date' => '2026-11-09 10:00:00', 'reason' => 'Rehoused']);

    $page = new BoxMovementHistoryReport;
    $columns = array_keys($page->getXlsxColumns());
    $row = (new ReflectionMethod($page, 'movementRow'))->invoke(null, BoxMovement::query()->with(['document.authorities', 'fromBox.batch', 'toBox.batch', 'user'])->first());

    expect($columns)->toBe(['Movement date', 'Catalogue Identifier', 'Actual Authority Reference Code', 'Volume', 'Creator', 'Document dates', 'From box', 'To box', 'Reason', 'By'])
        ->and(array_slice($row, 1, 8))->toBe(['R175/3', 'R175', '3', 'Caxaro, Brandano', '1500-1510', 'Batch 4 / Box 54', 'NRA 10', 'Rehoused']);

    Livewire::test(BoxMovementHistoryReport::class)
        ->assertOk()
        ->assertSee('Document movement history')
        ->assertSee('Batch 4 / Box 54')
        ->assertSee('Caxaro, Brandano');
});

it('counts documents, not flags, in flags by type', function (): void {
    $doc = cr10_doc();
    $other = cr10_doc();
    $flag = fn (Document $d, string $status) => DocumentFlag::withoutGlobalScopes()->create([
        'document_id' => $d->id, 'repository_id' => $this->repo->id, 'type' => 'mould_treatment', 'severity' => 'warning',
        'status' => $status, 'title' => 'Mould', 'flagged_at' => now(),
    ]);
    $flag($doc, 'open');
    $flag($doc, 'acknowledged');
    $flag($doc, 'resolved');
    $flag($doc, 'resolved');
    $flag($other, 'open');

    $rows = cr10_rows(new FlagsByTypeReport);

    expect($rows[0][2])->toBe(2)   // two documents with an open flag
        ->and($rows[0][3])->toBe(1); // one document with resolved flags

    Livewire::test(FlagsByTypeReport::class)->assertOk()->assertSee('# Documents open');
});
