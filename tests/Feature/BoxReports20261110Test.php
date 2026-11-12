<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Pages\Reports\BoxesPendingDisinfestationReport;
use App\Filament\Pages\Reports\BoxesReadyToDestroyReport;
use App\Filament\Pages\Reports\MassMigrationReport;
use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\Reports\BoxReportCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client feedback 2026-11-10: the four box reports — stock take per box / per
 * document (2), boxes pending disinfestation (3), mass migration (4) and boxes
 * ready to be destroyed (11). Driven through the real report pages.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);

    $this->repoA = Repository::factory()->create(['code' => 'BRA']);
    $this->repoB = Repository::factory()->create(['code' => 'BRB']);
    $this->roomA = Location::withoutGlobalScope(RepositoryScope::class)->create(['name' => 'Room A', 'type' => 'room', 'repository_id' => null, 'is_active' => true]);
    $this->roomB = Location::withoutGlobalScope(RepositoryScope::class)->create(['name' => 'Room B', 'type' => 'room', 'repository_id' => null, 'is_active' => true]);

    $this->batch = fn (string $n, ?Repository $r = null): Batch => Batch::withoutGlobalScope(RepositoryScope::class)
        ->create(['batch_number' => $n, 'type' => 'MAIN_COLLECTION', 'repository_id' => ($r ?? $this->repoA)->id, 'is_active' => true]);
    $this->ras = fn (Batch $b, string $n, array $a = []): Box => Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->create(array_merge(['box_type' => 'RAS', 'box_number' => $n, 'batch_id' => $b->id, 'barcode' => 'BX' . $b->batch_number . '-' . $n, 'barcode_status' => 'IN', 'is_legacy' => false, 'location_id' => $this->roomA->id], $a));
    $this->nra = fn (string $n, array $a = []): Box => Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->create(array_merge(['box_type' => 'NRA', 'box_number' => $n, 'batch_id' => null, 'repository_id' => $this->repoA->id, 'provenance_unknown' => true, 'is_legacy' => false, 'location_id' => $this->roomA->id], $a));
    $this->series = Series::create(['code' => 'BRS', 'title' => 'Series', 'is_active' => true]);
    $this->doc = fn (?Box $box, array $a = []): Document => Document::withoutGlobalScope(RepositoryScope::class)
        ->create(array_merge(['identifier' => 'D' . uniqid(), 'repository_id' => $this->repoA->id, 'series_id' => $this->series->id, 'document_type' => 'Register Volume', 'current_box_id' => $box?->id], $a));
    $this->authority = fn (string $code, string $surname = 'Abela'): Authority => Authority::query()
        ->create(['alternative_identifier' => $code, 'surname' => $surname, 'given_names' => 'Placido', 'entity_type' => 'PERSON']);
});

/**
 * The labels of the rows on screen, in order.
 *
 * @return list<string>
 */
function br_labels(Testable $page): array
{
    return $page->instance()->getTableRecords()
        ->map(fn ($r): string => $r instanceof Box ? $r->shortLabel() : Box::shortLabelFrom($r->box_type, $r->box_number, $r->batch_number))
        ->values()->all();
}

function br_csv(Testable $page): string
{
    return (string) base64_decode((string) $page->callAction('exportCsv')->effects['download']['content'], true);
}

// ── Report 2a: stock take per box ──────────────────────────────────────────

it('lists boxes by location in batch / box number order, numbers compared as numbers', function (): void {
    $b2 = ($this->batch)('2');
    $b10 = ($this->batch)('10');
    ($this->ras)($b10, '5');
    ($this->ras)($b2, '54');
    ($this->ras)($b2, '9');
    ($this->nra)('10');
    ($this->nra)('2');
    ($this->ras)($b2, '1', ['location_id' => $this->roomB->id]);
    ($this->ras)($b2, '7', ['destroyed_at' => now(), 'destroyed_reason' => 'test']);

    $page = Livewire::test(StockTakeReport::class)->assertOk();

    expect(br_labels($page))->toBe([
        'Batch 2 / Box 9', 'Batch 2 / Box 54', 'Batch 10 / Box 5', 'NRA 2', 'NRA 10', // Room A
        'Batch 2 / Box 1',                                                          // Room B
    ]);
    $page->assertSee('Room A · 5 boxes')->assertSee('Room B · 1 box')->assertDontSee('Box 7');
});

it('sorts a box with no type among the RAS boxes and text batch numbers after the numbered ones', function (): void {
    $b2 = ($this->batch)('2');
    $b29 = ($this->batch)('29');
    $unknown = ($this->batch)('Unknown');
    ($this->ras)($unknown, '3');
    ($this->ras)($b29, '32', ['box_type' => null]);
    ($this->ras)($b2, '11');

    expect(br_labels(Livewire::test(StockTakeReport::class)->assertOk()))
        ->toBe(['Batch 2 / Box 11', 'Batch 29 / Box 32', 'Batch Unknown / Box 3']);
});

it('adds documents kept outside their box, at their own location, on request', function (): void {
    $box = ($this->ras)(($this->batch)('3'), '4');
    $mounted = ($this->doc)($box, ['custody_status' => 'mounted_no_box', 'catalogue_identifier' => 'R5/001', 'location_id' => $this->roomB->id]);
    $conservation = ($this->doc)($box, ['custody_status' => 'conservation_box', 'catalogue_identifier' => 'R5/002']);
    ($this->doc)($box, ['catalogue_identifier' => 'R5/003']); // in box: never its own row

    $page = Livewire::test(StockTakeReport::class);
    expect($page->instance()->getTableRecords()->pluck('stock_type')->all())->toBe(['box']);

    $page->filterTable('include_out_of_box', true);
    $documents = $page->instance()->getTableRecords()->where('stock_type', 'document')->keyBy('source_id');

    expect($documents->keys()->sort()->values()->all())
        ->toBe(collect([$mounted->id, $conservation->id])->sort()->values()->all())
        ->and($documents[$mounted->id]->location_name)->toBe('Room B')
        ->and($documents[$conservation->id]->location_name)->toBe('Room A'); // the box's

    $page->assertSee('Room A · 1 box, 1 document')->assertSee('Room B · 1 document')->assertSee('Mounted');

    expect(br_csv($page))->toContain('"Room B",Document,"R5/001 (Of Batch 3 / Box 4)"');
});

it('scopes the stock take to the repositories of the viewer', function (): void {
    ($this->ras)(($this->batch)('4'), '1');
    ($this->ras)(($this->batch)('5', $this->repoB), '1');

    $this->actingAs(bl_actor('viewer', $this->repoA));

    expect(br_labels(Livewire::test(StockTakeReport::class)))->toBe(['Batch 4 / Box 1']);
});

// ── Report 2b: stock take per document ─────────────────────────────────────

it('lists documents by location, sorted by catalogue identifier in natural order, with every authority code', function (): void {
    $box = ($this->ras)(($this->batch)('6'), '1');
    $r10 = ($this->doc)($box, ['catalogue_identifier' => 'R10/001', 'volume_number' => '3', 'dates' => '1738 - 1739']);
    $r100 = ($this->doc)($box, ['catalogue_identifier' => 'R100/001']);
    $r2 = ($this->doc)($box, ['catalogue_identifier' => 'R2/001']);
    $elsewhere = ($this->doc)($box, ['catalogue_identifier' => 'R1/001', 'location_id' => $this->roomB->id]);
    $r10->authorities()->attach([($this->authority)('R150', 'Abela')->id, ($this->authority)('R151', 'Zammit')->id]);

    $page = Livewire::withQueryParams(['mode' => 'document'])->test(StockTakeReport::class)->assertOk();

    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([$r2->id, $r10->id, $r100->id, $elsewhere->id]);
    $page->assertSee('Stock take per document')
        ->assertSee('Room A · 3 documents')
        ->assertSee('Room B · 1 document')
        ->assertSee('R150')->assertSee('R151')
        ->assertSee('Abela, Placido')
        ->assertSee('1738 - 1739');

    expect(br_csv($page))->toContain('"Room A",R10/001,"R150; R151",3,"Abela, Placido; Zammit, Placido","1738 - 1739"');
});

it('keeps the per-document view in a saved template', function (): void {
    Livewire::withQueryParams(['mode' => 'document'])->test(StockTakeReport::class)
        ->callAction('save_as_template', ['name' => 'By document']);
    $tpl = ReportTemplate::query()->where('name', 'By document')->firstOrFail();

    expect($tpl->filters['_mode'] ?? null)->toBe('document');

    Livewire::withQueryParams(['template' => $tpl->id])->test(StockTakeReport::class)
        ->assertSet('mode', 'document');
});

// ── Report 3: boxes pending disinfestation ─────────────────────────────────

it('lists IN boxes holding at least one document in the box never disinfested', function (): void {
    $b = ($this->batch)('7');
    $mixed = ($this->ras)($b, '1');
    ($this->doc)($mixed, []);
    ($this->doc)($mixed, ['disinfestation_date' => '2026-01-10']);

    $out = ($this->ras)($b, '2', ['barcode_status' => 'OUT']);
    ($this->doc)($out, []);

    $done = ($this->ras)($b, '3');
    ($this->doc)($done, ['disinfestation_date' => '2026-01-10']);

    $mountedOnly = ($this->ras)($b, '4');
    ($this->doc)($mountedOnly, ['custody_status' => 'mounted_no_box']);

    $onItsWay = ($this->ras)($b, '5');
    ($this->doc)($onItsWay, ['is_in_disinfestation' => true]);

    $legacyRound = ($this->ras)($b, '6');
    ($this->doc)($legacyRound, ['disinfestation_date_1' => '2019-05-01']);

    $destroyed = ($this->ras)($b, '8');
    ($this->doc)($destroyed, []);
    $destroyed->update(['destroyed_at' => now(), 'destroyed_reason' => 'test']);

    $page = Livewire::test(BoxesPendingDisinfestationReport::class)
        ->assertCanSeeTableRecords([$mixed])
        ->assertCanNotSeeTableRecords([$out, $done, $mountedOnly, $onItsWay, $legacyRound, $destroyed]);

    expect((int) $page->instance()->getTableRecords()->first()->pending_documents_count)->toBe(1);
    expect(br_csv($page))->toContain('"Batch 7 / Box 1",BX7-1,IN,,1,"Room A"');
});

it('offers the send-to-disinfestation bulk action on the pending boxes', function (): void {
    expect(Livewire::test(BoxesPendingDisinfestationReport::class)->instance()->getTable()->getBulkAction('sendToDisinfestation'))->not->toBeNull();
});

// ── Report 4: mass migration ───────────────────────────────────────────────

it('compares authority codes by number and matches a document by any of its codes', function (): void {
    $parse = fn (string $s) => BoxReportCriteria::parseCode($s);
    $in = fn (string $code): bool => BoxReportCriteria::codeInRange($code, $parse('R150'), $parse('R165'));

    expect($in('R150'))->toBeTrue()
        ->and($in('R151'))->toBeTrue()
        ->and($in('R163A'))->toBeTrue()
        ->and($in('R165'))->toBeTrue()
        ->and($in('R1500'))->toBeFalse()
        ->and($in('R16'))->toBeFalse()
        ->and($in('I155'))->toBeFalse()
        ->and($in('Unknown'))->toBeFalse()
        ->and(BoxReportCriteria::codeInRange('R170', $parse('R150'), null))->toBeTrue()
        ->and(BoxReportCriteria::codeInRange('R149', $parse('R150'), null))->toBeFalse();
});

it('lists every box with at least one matching document, with the subseries of a chosen series', function (): void {
    $parent = Series::create(['code' => 'R', 'title' => 'Register Copies', 'is_active' => true]);
    $reg = Series::create(['code' => 'REG', 'title' => 'Registers', 'is_active' => true, 'parent_id' => $parent->id]);
    $misc = Series::create(['code' => 'M', 'title' => 'Miscellanea', 'is_active' => true]);
    $b = ($this->batch)('8');

    $mixed = ($this->ras)($b, '1');
    ($this->doc)($mixed, ['series_id' => $reg->id])->authorities()->attach(($this->authority)('R160')->id);
    ($this->doc)($mixed, ['series_id' => $misc->id, 'document_type' => 'Miscellanea']);

    $farAway = ($this->ras)($b, '2');
    ($this->doc)($farAway, ['series_id' => $reg->id])->authorities()->attach(($this->authority)('R1500')->id);

    $twoCodes = ($this->ras)($b, '3');
    ($this->doc)($twoCodes, ['series_id' => $reg->id])->authorities()->attach([($this->authority)('R10')->id, ($this->authority)('R151')->id]);

    $unknown = ($this->ras)($b, '4');
    ($this->doc)($unknown, ['series_id' => $misc->id, 'document_type' => 'Miscellanea'])->authorities()->attach(($this->authority)('Unknown')->id);

    $empty = ($this->ras)($b, '5');

    // No criterion: every box that holds a document.
    Livewire::test(MassMigrationReport::class)
        ->assertCanSeeTableRecords([$mixed, $farAway, $twoCodes, $unknown])
        ->assertCanNotSeeTableRecords([$empty]);

    // The parent series brings in its subseries REG.
    Livewire::test(MassMigrationReport::class)
        ->filterTable('series', [$parent->id])
        ->assertCanSeeTableRecords([$mixed, $farAway, $twoCodes])
        ->assertCanNotSeeTableRecords([$unknown]);

    // Series + type + range: R1500 is out, a document with R10 and R151 is in.
    $page = Livewire::test(MassMigrationReport::class)
        ->filterTable('series', [$reg->id])
        ->filterTable('document_type', ['Register Volume'])
        ->filterTable('authority_range', ['from' => 'R150', 'to' => 'R165'])
        ->assertCanSeeTableRecords([$mixed, $twoCodes])
        ->assertCanNotSeeTableRecords([$farAway, $unknown]);
    $row = $page->instance()->getTableRecords()->firstWhere('id', $mixed->id);
    expect((int) $row->matching_documents_count)->toBe(1)->and((int) $row->documents_count)->toBe(2);
    expect(br_csv($page))->toContain('"Batch 8 / Box 1",,BX8-1,IN,"Room A",1,2');

    // Series M alone, no range: the Miscellanea with an Unknown code are found.
    Livewire::test(MassMigrationReport::class)
        ->filterTable('series', [$misc->id])
        ->assertCanSeeTableRecords([$mixed, $unknown])
        ->assertCanNotSeeTableRecords([$farAway, $twoCodes]);

    // A range with "also documents with no code".
    Livewire::test(MassMigrationReport::class)
        ->filterTable('authority_range', ['from' => 'R150', 'to' => 'R165', 'include_no_code' => true])
        ->assertCanSeeTableRecords([$mixed, $twoCodes, $unknown])
        ->assertCanNotSeeTableRecords([$farAway]);
});

it('scopes the mass migration to the repositories of the viewer', function (): void {
    $mine = ($this->ras)(($this->batch)('11'), '1');
    ($this->doc)($mine, []);
    $theirs = ($this->ras)(($this->batch)('12', $this->repoB), '1');
    ($this->doc)($theirs, ['repository_id' => $this->repoB->id]);

    $this->actingAs(bl_actor('viewer', $this->repoA));

    Livewire::test(MassMigrationReport::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

// ── Report 11: boxes ready to be destroyed ────────────────────────────────

it('lists RAS boxes whose every document has a catalogue identifier, empty text not counting', function (): void {
    $b = ($this->batch)('13');
    $done = ($this->ras)($b, '1');
    ($this->doc)($done, ['catalogue_identifier' => 'R1/001']);
    $blank = ($this->ras)($b, '2');
    ($this->doc)($blank, ['catalogue_identifier' => 'R1/002']);
    ($this->doc)($blank, ['catalogue_identifier' => '']);
    $onlyTrashed = ($this->ras)($b, '3');
    ($this->doc)($onlyTrashed, ['catalogue_identifier' => 'R1/003'])->delete();
    $nra = ($this->nra)('20');
    ($this->doc)($nra, ['catalogue_identifier' => 'R1/004']);

    Livewire::test(BoxesReadyToDestroyReport::class)
        ->assertCanSeeTableRecords([$done])
        ->assertCanNotSeeTableRecords([$blank, $onlyTrashed, $nra]);

    expect($blank->canBeDestroyed()['ok'])->toBeFalse();
});

// ── All four: on the landing page, exports render ─────────────────────────

it('lists the new reports on the landing page and every export renders', function (): void {
    $box = ($this->ras)(($this->batch)('14'), '1');
    ($this->doc)($box, ['catalogue_identifier' => 'R3/001']);

    Livewire::test(Reports::class)
        ->assertSee('Boxes pending disinfestation')
        ->assertSee('Mass migration');

    $pdfGroups = null;
    View::composer('reports.pdf-layout', function ($view) use (&$pdfGroups): void {
        $pdfGroups = $view->getData()['group_counts'] ?? null;
    });

    $pages = [
        [StockTakeReport::class, []],
        [StockTakeReport::class, ['mode' => 'document']],
        [BoxesPendingDisinfestationReport::class, []],
        [MassMigrationReport::class, []],
        [BoxesReadyToDestroyReport::class, []],
    ];
    foreach ($pages as [$class, $query]) {
        foreach (['exportCsv', 'exportXlsx', 'exportPdf'] as $action) {
            $effects = Livewire::withQueryParams($query)->test($class)->callAction($action)->effects;
            expect($effects['download']['content'] ?? null)->not->toBeEmpty("{$class} {$action}");
        }
    }

    // The stock take PDF is headed by location.
    Livewire::test(StockTakeReport::class)->callAction('exportPdf');
    expect($pdfGroups)->toBe(['Room A' => 1]);
});
