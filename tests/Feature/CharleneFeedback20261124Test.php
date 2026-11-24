<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxesPendingDisinfestationReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Resources\AuthorityResource\Pages\EditAuthority;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\AuthorityResource\Pages\ViewAuthority;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\Reports\DisinfestationCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client feedback 2026-11-24 (Charlene):
 *  1. a link from the authority to all documents by that creator;
 *  2. Documents pending disinfestation: Creator and Authority Identifier
 *     before the document identifier;
 *  3. Boxes pending disinfestation: batch and box apart (filterable by batch,
 *     split in the Excel), the box spaces by Current Box Type, and a fill of
 *     the next N box spaces (250 for one Comtec round).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);

    $this->repo = Repository::factory()->create(['code' => 'CFA']);
    $this->room = Location::withoutGlobalScope(RepositoryScope::class)->create(['name' => 'Room A', 'type' => 'room', 'repository_id' => null, 'is_active' => true]);
    $this->series = Series::create(['code' => 'CFS', 'title' => 'Series', 'is_active' => true]);

    $this->batch = fn (string $n): Batch => Batch::withoutGlobalScope(RepositoryScope::class)
        ->create(['batch_number' => $n, 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->ras = fn (Batch $b, string $n, array $a = []): Box => Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->create(array_merge(['box_type' => 'RAS', 'box_number' => $n, 'batch_id' => $b->id, 'barcode' => 'BX' . $b->batch_number . '-' . $n, 'barcode_status' => 'IN', 'is_legacy' => false, 'location_id' => $this->room->id, 'current_box_type' => 'RAS Box'], $a));
    $this->doc = fn (?Box $box, array $a = []): Document => Document::withoutGlobalScope(RepositoryScope::class)
        ->create(array_merge(['identifier' => 'D' . uniqid(), 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'Register Volume', 'current_box_id' => $box?->id], $a));
    $this->authority = fn (string $code, string $surname, string $given = 'Placido'): Authority => Authority::query()
        ->create(['alternative_identifier' => $code, 'surname' => $surname, 'given_names' => $given, 'entity_type' => 'PERSON']);

    CurrentBoxType::query()->updateOrCreate(['code' => 'RAS Box'], ['label' => 'RAS Box', 'is_active' => true, 'counts_as' => 1]);
    CurrentBoxType::query()->updateOrCreate(['code' => 'Big Brown Box'], ['label' => 'Big Brown Box', 'is_active' => true, 'counts_as' => 2]);
    DisinfestationCapacity::flushCache();
});

afterEach(fn () => DisinfestationCapacity::flushCache());

function cf24_csv(Testable $page): string
{
    // Without the UTF-8 byte-order mark Excel needs.
    return ltrim((string) base64_decode((string) $page->callAction('exportCsv')->effects['download']['content'], true), "\u{FEFF}");
}

// ── 1. Documents by this creator ────────────────────────────────────────────

it('links the authority to the Documents list filtered on that creator', function (): void {
    $abela = ($this->authority)('R12', 'Abela');
    $borg = ($this->authority)('R13', 'Borg');
    $mine = ($this->doc)(null);
    $mineToo = ($this->doc)(null);
    $other = ($this->doc)(null);
    $mine->authorities()->attach($abela->id);
    $mineToo->authorities()->attach([$abela->id, $borg->id]);
    $other->authorities()->attach($borg->id);

    foreach ([ViewAuthority::class, EditAuthority::class] as $page) {
        Livewire::test($page, ['record' => $abela->getRouteKey()])
            ->assertActionVisible('documentsByCreator')
            ->assertActionHasLabel('documentsByCreator', 'Documents by this creator (2)')
            ->assertActionHasUrl('documentsByCreator', DocumentResource::creatorDocumentsUrl($abela));
    }
    Livewire::test(ListAuthorities::class)->assertTableActionVisible('documentsByCreator', $abela);

    // The link's rule, as the Documents list reads it from the URL.
    parse_str((string) parse_url(DocumentResource::creatorDocumentsUrl($abela), PHP_URL_QUERY), $query);
    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', $query['filters']['queryBuilder'])
        ->assertCanSeeTableRecords([$mine, $mineToo])
        ->assertCanNotSeeTableRecords([$other]);
});

it('finds creators for the Creator rule by surname, given names or R-code', function (): void {
    $abela = ($this->authority)('R12', 'Abela');
    ($this->authority)('R130', 'Borg', 'Giuseppe');

    expect(DocumentResource::creatorSearchOptions('Abel'))->toBe([$abela->id => 'Abela, Placido (R12)'])
        ->and(DocumentResource::creatorSearchOptions('R12'))->toBe([$abela->id => 'Abela, Placido (R12)'])
        ->and(DocumentResource::creatorSearchOptions('Giuseppe'))->toHaveCount(1);
});

// ── 2. Documents pending disinfestation ─────────────────────────────────────

it('puts Creator and Authority Identifier before the document identifier, on screen and in the exports', function (): void {
    $abela = ($this->authority)('R12', 'Abela');
    $b = ($this->batch)('46');
    $doc = ($this->doc)(($this->ras)($b, '1'), ['identifier' => 'DOC-46-1']);
    $doc->authorities()->attach($abela->id);

    $page = Livewire::test(PendingDisinfestationReport::class)->assertCanSeeTableRecords([$doc]);

    $names = array_keys($page->instance()->getTable()->getColumns());
    expect(array_slice($names, 0, 3))->toBe(['creator_names', 'authority_identifiers', 'identifier']);
    $page->assertTableColumnStateSet('creator_names', ['Abela, Placido'], $doc)
        ->assertTableColumnStateSet('authority_identifiers', ['R12'], $doc);

    $csv = cf24_csv($page);
    expect(strtok($csv, "\n"))->toStartWith('Creator,"Authority Identifier",Identifier,Type')
        ->and($csv)->toContain('"Abela, Placido",R12,DOC-46-1');
    expect(array_slice(array_keys($page->instance()->getXlsxColumns()), 0, 3))->toBe(['Creator', 'Authority Identifier', 'Identifier']);
});

// ── 3. Boxes pending disinfestation ─────────────────────────────────────────

it('shows batch and box apart, filters by batch and gives the box spaces by Current Box Type', function (): void {
    $b45 = ($this->batch)('45');
    $b46 = ($this->batch)('46');
    $small = ($this->ras)($b45, '3');
    $big = ($this->ras)($b46, '8', ['current_box_type' => 'Big Brown Box']);
    ($this->doc)($small);
    ($this->doc)($big);

    $page = Livewire::test(BoxesPendingDisinfestationReport::class)
        ->assertTableColumnStateSet('batch.batch_number', '46', $big)
        ->assertTableColumnStateSet('box_label', '8', $big)
        ->assertTableColumnStateSet('box_spaces', 2, $big)
        ->assertTableColumnStateSet('box_spaces', 1, $small);

    $csv = cf24_csv($page);
    expect(strtok($csv, "\n"))->toBe('Batch,Box,Barcode,Status,"Current Box Type","Box spaces","Documents pending",Location')
        ->and($csv)->toContain('46,8,BX46-8,IN,"Big Brown Box",2,1,"Room A"')
        ->and($csv)->toContain('45,3,BX45-3,IN,"RAS Box",1,1,"Room A"');

    $page->filterTable('batch_id', [$b46->id])
        ->assertCanSeeTableRecords([$big])
        ->assertCanNotSeeTableRecords([$small]);
});

it('fills the next N box spaces in list order, a Big Brown Box taking two', function (): void {
    $b = ($this->batch)('46');
    $one = ($this->ras)($b, '1');
    $two = ($this->ras)($b, '2', ['current_box_type' => 'Big Brown Box']);
    $three = ($this->ras)($b, '3');
    $four = ($this->ras)($b, '4');
    foreach ([$one, $two, $three, $four] as $box) {
        ($this->doc)($box);
    }

    // 1 + 2 = 3 spaces: boxes 1 and 2.
    $page = Livewire::test(BoxesPendingDisinfestationReport::class)
        ->filterTable('box_spaces', ['spaces' => 3])
        ->assertCanSeeTableRecords([$one, $two])
        ->assertCanNotSeeTableRecords([$three, $four]);
    expect(cf24_csv($page))->toContain('46,2,')->not->toContain('46,3,');

    // 2 spaces: box 1 fits, the Big Brown Box would make 3 — the fill stops
    // there rather than jumping ahead to box 3.
    Livewire::test(BoxesPendingDisinfestationReport::class)
        ->filterTable('box_spaces', ['spaces' => 2])
        ->assertCanSeeTableRecords([$one])
        ->assertCanNotSeeTableRecords([$two, $three, $four]);

    // Combined with the batch filter, the fill counts only that batch.
    $other = ($this->batch)('45');
    $first = ($this->ras)($other, '1');
    ($this->doc)($first);
    Livewire::test(BoxesPendingDisinfestationReport::class)
        ->filterTable('batch_id', [$b->id])
        ->filterTable('box_spaces', ['spaces' => 1])
        ->assertCanSeeTableRecords([$one])
        ->assertCanNotSeeTableRecords([$first]);

    // Empty: every box.
    Livewire::test(BoxesPendingDisinfestationReport::class)
        ->filterTable('box_spaces', ['spaces' => null])
        ->assertCanSeeTableRecords([$one, $two, $three, $four, $first]);
});
