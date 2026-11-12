<?php

declare(strict_types=1);

use App\Filament\Actions\Boxes\MoveBoxToLocationAction;
use App\Filament\Resources\AuthorityResource\Pages\CreateAuthority;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/**
 * Client 2026-11-06 (Fabio.docx and her answers): joins per rule in the
 * filters, one disinfestation round per date, future practice years, Set
 * location removed, documents physically in a box follow it, Creator by
 * surname.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $this->repo));
    $this->batch = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '9']);
});

function cf_doc(string $identifier, array $attrs = []): Document
{
    return Document::factory()->create(array_merge([
        'repository_id' => test()->repo->id,
        'catalogue_identifier' => $identifier,
        'identifier' => $identifier,
        'document_type' => null,
    ], $attrs));
}

/** @param list<array{0: string, 1: string, 2?: string}> $rules [join, identifier] */
function cf_rules(array $rules): array
{
    $out = [];
    foreach ($rules as $i => $rule) {
        $out['r' . $i] = ['type' => 'identifier', 'data' => [
            'connector' => $rule[0],
            'operator' => $rule[2] ?? 'equals',
            'settings' => ['text' => $rule[1]],
        ]];
    }

    return ['match' => 'all', 'rules' => $out];
}

/* ---- Issue 1: AND / OR / NOT on each rule --------------------------------- */

it('reads the rules left to right: A OR B AND C is (A OR B) AND C', function (): void {
    $a = cf_doc('A-1');
    $b = cf_doc('B-1');
    cf_doc('C-1');

    // (A OR B) AND "contains -1": both stay. Under AND-before-OR it would read
    // A OR (B AND ...), which also keeps both, so the next case tells them apart.
    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', cf_rules([['and', 'A-1'], ['or', 'B-1'], ['and', '-1', 'contains']]))
        ->assertCanSeeTableRecords([$a, $b])
        ->assertCountTableRecords(2);

    // (A OR B) AND "contains B": only B. AND-before-OR would keep A as well.
    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', cf_rules([['and', 'A-1'], ['or', 'B-1'], ['and', 'B', 'contains']]))
        ->assertCanSeeTableRecords([$b])
        ->assertCountTableRecords(1);
});

it('applies AND NOT to everything above it', function (): void {
    $a = cf_doc('A-1');
    cf_doc('A-2');
    cf_doc('B-1');

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', cf_rules([['and', 'A', 'contains'], ['not', 'A-2']]))
        ->assertCanSeeTableRecords([$a])
        ->assertCountTableRecords(1);
});

it('lets an incomplete OR rule match nothing extra', function (): void {
    $a = cf_doc('A-1');
    cf_doc('B-1');

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', cf_rules([['and', 'A-1'], ['or', '']]))
        ->assertCanSeeTableRecords([$a])
        ->assertCountTableRecords(1);
});

it('still honours links made with the list-wide "any" choice', function (): void {
    $a = cf_doc('A-1');
    $b = cf_doc('B-1');
    cf_doc('C-1');
    $rules = cf_rules([['and', 'A-1'], ['and', 'B-1']]);
    foreach ($rules['rules'] as &$rule) {
        unset($rule['data']['connector']);
    }
    $rules['match'] = 'any';

    Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', $rules)
        ->assertCanSeeTableRecords([$a, $b])
        ->assertCountTableRecords(2);
});

it('names the joins in the active filter', function (): void {
    cf_doc('A-1');

    $indicators = Livewire::test(ListDocuments::class)
        ->filterTable('queryBuilder', cf_rules([['and', 'A-1'], ['or', 'B-1'], ['not', 'C-1']]))
        ->instance()->getTable()->getFilter('queryBuilder')->getIndicators();

    expect(collect($indicators)->map->getLabel()->implode(''))
        ->toContain(' OR ')
        ->toContain(' AND NOT ');
});

it('offers the join on the rules and lets the rule list be searched', function (): void {
    Livewire::test(ListDocuments::class)
        ->assertSee('Search');
    $filter = Livewire::test(ListDocuments::class)->instance()->getTable()->getFilter('queryBuilder');
    expect($filter->getMaxNestingDepth())->toBe(1);
});

/* ---- Issue 2: one disinfestation round per date --------------------------- */

it('shows a date once when the latest disinfestation is also a legacy round', function (): void {
    $doc = cf_doc('D-1', ['disinfestation_date' => '2025-06-10', 'disinfestation_date_1' => '2025-06-10']);

    $timeline = $doc->fresh()->disinfestationTimeline();
    expect($timeline)->toHaveCount(1)
        ->and($timeline->first()['label'])->toBe('Legacy round #1');

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertSee('Legacy round #1')
        ->assertDontSee('Current</');

    $later = cf_doc('D-2', ['disinfestation_date' => '2026-01-05', 'disinfestation_date_1' => '2025-06-10']);
    expect($later->fresh()->disinfestationTimeline()->pluck('label')->all())->toBe(['Legacy round #1', 'Current']);
});

/* ---- Issue 3: practice and NTG years in the future ------------------------ */

it('lets practice and NTG years be in the future', function (): void {
    $next = (int) now()->year + 1;

    Livewire::test(CreateAuthority::class)
        ->fillForm([
            'alternative_identifier' => 'R9001',
            'surname' => 'Abela',
            'given_names' => 'Giovanni',
            'entity_type' => 'Person',
            'practice_dates_start' => (string) $next,
            'ntg_dates_start' => (string) $next,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $authority = Authority::query()->where('alternative_identifier', 'R9001')->sole();
    expect((int) $authority->practice_dates_start)->toBe($next)
        ->and((int) $authority->ntg_dates_start)->toBe($next);
});

/* ---- Option 5: Set location is gone --------------------------------------- */

it('no longer offers Set location', function (): void {
    $doc = cf_doc('S-1');

    Livewire::test(ListDocuments::class)->assertTableBulkActionDoesNotExist('bulkSetLocation');
    expect(class_exists('App\\Filament\\Actions\\Documents\\SetLocationAction'))->toBeFalse();
    Livewire::test(DocumentResource\Pages\EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertActionDoesNotExist('setLocation');
});

/* ---- Question 3: what is in the box follows the box ----------------------- */

function cf_box(array $attrs = []): Box
{
    return Box::factory()->create(array_merge(['batch_id' => test()->batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN'], $attrs));
}

function cf_loc(string $name, string $type = 'external'): Location
{
    return qf_location(test()->repo->id, ['name' => $name, 'type' => $type]);
}

it('sends to disinfestation only the documents physically in the box, and they follow it', function (): void {
    $archive3 = cf_loc('Archive 3', 'room');
    $disinfestation = cf_loc('Disinfestation');
    $box = cf_box();
    $inBox = cf_doc('IN-1', ['current_box_id' => $box->id, 'batch_id' => $box->batch_id, 'custody_status' => 'in_box', 'location_id' => $archive3->id]);
    $elsewhere = cf_doc('OUT-1', ['current_box_id' => $box->id, 'batch_id' => $box->batch_id, 'custody_status' => 'not_in_box', 'location_id' => $archive3->id]);

    Livewire::test(ListBoxes::class)->callTableBulkAction('sendToDisinfestation', [$box]);

    expect($inBox->fresh()->is_in_disinfestation)->toBeTrue()
        ->and($inBox->fresh()->location_id)->toBeNull()
        ->and($inBox->fresh()->effectiveLocation()?->is($disinfestation))->toBeTrue()
        ->and($elsewhere->fresh()->is_in_disinfestation)->toBeFalse()
        ->and((int) $elsewhere->fresh()->location_id)->toBe($archive3->id);

    $audit = Audit::query()->where('event', 'document.followed_box')->where('auditable_id', $inBox->id)->sole();
    expect($audit->old_values['location_id'])->toBe($archive3->id);

    // Finishing brings back, and dates, only what went.
    $ras = cf_loc('RAS');
    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras']);

    expect($inBox->fresh()->disinfestation_date?->toDateString())->toBe('2026-10-01')
        ->and($inBox->fresh()->effectiveLocation()?->is($ras))->toBeTrue()
        ->and($elsewhere->fresh()->disinfestation_date)->toBeNull();
});

it('moves the documents physically in a box with it on Relocate and Move', function (): void {
    $archive3 = cf_loc('Archive 3', 'room');
    $archive8 = cf_loc('Archive 8', 'room');
    $archive9 = cf_loc('Archive 9', 'room');
    $box = cf_box(['box_type' => 'NRA', 'box_number' => 'NRA 77', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);
    $inBox = cf_doc('IN-2', ['current_box_id' => $box->id, 'custody_status' => 'in_box', 'location_id' => $archive3->id]);
    $mounted = cf_doc('MNT-2', ['current_box_id' => $box->id, 'custody_status' => 'mounted_no_box', 'location_id' => $archive3->id]);

    Livewire::test(ListBoxes::class)->callTableBulkAction('relocate', [$box], data: ['location_id' => $archive8->id]);

    expect($inBox->fresh()->location_id)->toBeNull()
        ->and($inBox->fresh()->effectiveLocation()?->is($archive8))->toBeTrue()
        ->and((int) $mounted->fresh()->location_id)->toBe($archive3->id);

    Livewire::test(ListBoxes::class)
        ->callTableAction(MoveBoxToLocationAction::make()->getName(), $box, data: ['to_location_id' => $archive9->id]);

    expect($inBox->fresh()->effectiveLocation()?->is($archive9))->toBeTrue()
        ->and((int) $mounted->fresh()->location_id)->toBe($archive3->id);
});

/* ---- Question 4: Creator by surname, surname first ------------------------ */

it('shows the Creator surname first and sorts by it', function (): void {
    $zammit = Authority::query()->create(['alternative_identifier' => 'R1', 'surname' => 'Zammit', 'given_names' => 'Anna', 'entity_type' => 'Person']);
    $abela = Authority::query()->create(['alternative_identifier' => 'R2', 'surname' => 'Abela', 'given_names' => 'Zeno', 'entity_type' => 'Person']);
    $abelaB = Authority::query()->create(['alternative_identifier' => 'R3', 'surname' => 'Abela', 'given_names' => 'Bianca', 'entity_type' => 'Person']);
    $z = cf_doc('Z-1');
    $z->authorities()->attach($zammit->id);
    $a = cf_doc('A-1');
    $a->authorities()->attach($abela->id);
    $b = cf_doc('B-1');
    $b->authorities()->attach($abelaB->id);

    expect(DocumentResource::creatorName($zammit))->toBe('Zammit, Anna');

    Livewire::test(ListDocuments::class)
        ->assertSee('Abela, Bianca')
        ->sortTable('creator_names')
        ->assertCanSeeTableRecords([$b, $a, $z], inOrder: true)
        ->sortTable('creator_names', 'desc')
        ->assertCanSeeTableRecords([$z, $a, $b], inOrder: true);
});
