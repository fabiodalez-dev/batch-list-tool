<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\BoxMovementsRelationManager;
use App\Filament\Support\DocumentRules;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Client 2026-11-05, Issue 12: the Document power actions.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    bl_seedShieldPermissions();
    foreach (['RAS', 'MAV', 'NRA'] as $i => $code) {
        BoxType::query()->firstOrCreate(['code' => $code], ['label' => $code, 'is_active' => true, 'sort_order' => $i]);
    }
    $this->repo = Repository::factory()->create(['code' => 'NRA']);
    $this->otherRepo = Repository::factory()->create(['code' => 'OTH']);
    $this->series = Series::factory()->create();
    $this->admin = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->admin);
});

function pa05_batch(string $number, ?Repository $repo = null): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $number, 'type' => 'NOTARY_ACCESSION', 'is_active' => true,
        'repository_id' => ($repo ?? test()->repo)->id,
    ]);
}

function pa05_box(string $type, string $number, ?Batch $batch = null, array $attrs = [], ?Repository $repo = null): Box
{
    return Box::withoutGlobalScopes()->create(array_merge([
        'box_type' => $type, 'box_number' => $number, 'batch_id' => $batch?->id,
        'repository_id' => $batch === null ? ($repo ?? test()->repo)->id : null,
        'barcode_status' => 'IN', 'is_legacy' => $type !== 'RAS', 'provenance_unknown' => $type !== 'RAS',
    ], $attrs));
}

function pa05_doc(array $attrs = [], ?Repository $repo = null): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'PA-' . uniqid(),
        'series_id' => test()->series->id,
        'repository_id' => ($repo ?? test()->repo)->id,
    ], $attrs));
}

function pa05_authority(string $code, string $form, array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'ID-' . $code, 'alternative_identifier' => $code, 'authorised_form_of_name' => $form,
        'surname' => strtok($form, ','), 'entity_type' => 'PERSON',
    ], $attrs));
}

/** A field of the mounted action's form: what the operator actually gets. */
function pa05_field(Testable $lw, string $name): Field
{
    return $lw->instance()->getSchema('mountedActionSchema0')->getFlatFields(withHidden: true)[$name];
}

function pa05_list(): Testable
{
    return Livewire::test(ListDocuments::class);
}

// ---------------------------------------------------------------------------
// Option 1: Move to box, the picker.
// ---------------------------------------------------------------------------

it('finds a box the way it reads, in Move to box, with no batch needed (client: "NRA 5 can\'t find it")', function (): void {
    $nra5 = pa05_box('NRA', '5');
    pa05_box('NRA', '12');
    $b4 = pa05_batch('4');
    $ras192 = pa05_box('RAS', '192', $b4);
    $ras5 = pa05_box('RAS', '5', $b4);
    $doc = pa05_doc();

    $picker = pa05_field(pa05_list()->mountTableBulkAction('bulkMoveToBox', [$doc]), 'to_box_id');

    foreach (['NRA 5', 'NRA5', 'nra 5'] as $search) {
        expect(array_key_first($picker->getSearchResults($search)))->toBe($nra5->id);
    }
    foreach (['Batch 4 / Box 192', '4/192'] as $search) {
        expect(array_key_first($picker->getSearchResults($search)))->toBe($ras192->id);
    }
    // A bare 5: both Box 5s, the batch-less one first.
    expect(array_keys($picker->getSearchResults('5')))->toBe([$nra5->id, $ras5->id]);

    // Not "Batch 37/Box 1 — RAS — IN" (her screenshot); the status only where a box has one.
    expect($picker->getSearchResults('NRA 5')[$nra5->id])->toBe('NRA 5')
        ->and($picker->getSearchResults('4/192')[$ras192->id])->toBe('Batch 4 / Box 192 — IN')
        ->and($picker->getOptionLabel())->toBeNull();
});

it('opens the Move to box list on assignable boxes and leaves out destroyed and PERM_OUT ones', function (): void {
    $b4 = pa05_batch('4');
    $good = pa05_box('RAS', '1', $b4);
    $nra = pa05_box('NRA', '5');
    $permOut = pa05_box('RAS', '2', $b4, ['barcode_status' => 'PERM_OUT']);
    $permOutNra = pa05_box('NRA', '6', null, ['barcode_status' => 'PERM_OUT']);
    $destroyed = pa05_box('NRA', '7', null, ['destroyed_at' => now()]);

    $picker = pa05_field(pa05_list()->mountTableBulkAction('bulkMoveToBox', [pa05_doc()]), 'to_box_id');

    expect(array_keys($picker->getOptions()))->toEqualCanonicalizing([$good->id, $nra->id])
        ->and($picker->getOptions()[$nra->id])->toBe('NRA 5')
        ->and($picker->getOptions()[$good->id])->toBe('Batch 4 / Box 1 — IN')
        ->and($picker->getSearchResults('NRA 6'))->not->toHaveKey($permOutNra->id)
        ->and($picker->getSearchResults('NRA 7'))->not->toHaveKey($destroyed->id)
        ->and($picker->getSearchResults('4/2'))->not->toHaveKey($permOut->id);
});

it('still finds a box by its barcode in Move to box', function (): void {
    $box = pa05_box('RAS', '192', pa05_batch('4'), ['barcode' => 'AA00110']);

    $picker = pa05_field(pa05_list()->mountTableBulkAction('bulkMoveToBox', [pa05_doc()]), 'to_box_id');

    expect($picker->getSearchResults('AA00110'))->toHaveKey($box->id);
});

it('keeps Box::searchOptions as it was for the other pickers: an empty search lists nothing', function (): void {
    pa05_box('NRA', '5');

    expect(Box::searchOptions(''))->toBe([]);
});

it('accepts a box found by search that is not on the opening list', function (): void {
    $b4 = pa05_batch('4');
    foreach (range(1, 55) as $n) {
        pa05_box('NRA', (string) $n);
    }
    $late = pa05_box('RAS', '192', $b4);
    $doc = pa05_doc();

    $lw = pa05_list()->mountTableBulkAction('bulkMoveToBox', [$doc]);
    expect(pa05_field($lw, 'to_box_id')->getOptions())->not->toHaveKey($late->id);

    $lw->setTableBulkActionData(['to_box_id' => $late->id])->callMountedTableBulkAction()->assertHasNoFormErrors();

    expect($doc->fresh()->current_box_id)->toBe($late->id);
});

// ---------------------------------------------------------------------------
// Option 1: Move to box, the move.
// ---------------------------------------------------------------------------

it('moves documents from a RAS box to a batch-less NRA box, keeping their batch', function (): void {
    $b4 = pa05_batch('4');
    $ras = pa05_box('RAS', '192', $b4);
    $nra5 = pa05_box('NRA', '5');
    $docs = [pa05_doc(['current_box_id' => $ras->id, 'batch_id' => $b4->id]), pa05_doc(['current_box_id' => $ras->id, 'batch_id' => $b4->id])];

    pa05_list()->callTableBulkAction('bulkMoveToBox', $docs, data: ['to_box_id' => $nra5->id, 'reason' => 'Rehoused'])
        ->assertHasNoFormErrors()
        ->assertNotified('2 document(s) moved to NRA 5');

    foreach ($docs as $doc) {
        $fresh = $doc->fresh();
        expect($fresh->current_box_id)->toBe($nra5->id)
            ->and($fresh->batch_id)->toBe($b4->id)
            ->and($fresh->custody_status)->toBe('in_box');

        $move = BoxMovement::query()->where('document_id', $doc->id)->sole();
        expect($move->from_box_id)->toBe($ras->id)
            ->and($move->to_box_id)->toBe($nra5->id)
            ->and($move->reason)->toBe('Rehoused')
            ->and($move->repository_id)->toBe($this->repo->id);
    }
});

it('moves a document from NRA 12 to NRA 5', function (): void {
    $nra12 = pa05_box('NRA', '12');
    $nra5 = pa05_box('NRA', '5');
    $doc = pa05_doc(['current_box_id' => $nra12->id]);

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $nra5->id])->assertHasNoFormErrors();

    expect($doc->fresh()->current_box_id)->toBe($nra5->id)
        ->and($doc->fresh()->batch_id)->toBeNull();
});

it('gives the document the batch of a batched target box, as before', function (): void {
    $b4 = pa05_batch('4');
    $b9 = pa05_batch('9');
    $target = pa05_box('RAS', '3', $b9);
    $doc = pa05_doc(['current_box_id' => pa05_box('RAS', '1', $b4)->id, 'batch_id' => $b4->id]);

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $target->id])->assertHasNoFormErrors();

    expect($doc->fresh()->batch_id)->toBe($b9->id);
});

it('moves a document in from every custody status: In box, and the location changes (option 3)', function (string $status): void {
    $old = Location::withoutGlobalScopes()->create(['name' => 'Conservation', 'type' => 'room', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $boxLocation = Location::withoutGlobalScopes()->create(['name' => 'Archive 1', 'type' => 'repository', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $box = pa05_box('NRA', '5', null, ['location_id' => $boxLocation->id]);
    $doc = pa05_doc(['custody_status' => $status, 'location_id' => $old->id]);

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id])->assertHasNoFormErrors();

    $fresh = $doc->fresh();
    expect($fresh->custody_status)->toBe('in_box')
        ->and($fresh->current_box_id)->toBe($box->id)
        ->and($fresh->location_id)->toBe($boxLocation->id);

    $move = BoxMovement::query()->where('document_id', $doc->id)->sole();
    expect($move->from_box_id)->toBeNull()->and($move->to_box_id)->toBe($box->id);
})->with(['not_in_box', 'mounted_no_box', 'conservation_box']);

it('writes the Location chosen in Move to box over the location of the box', function (): void {
    $boxLocation = qf_location($this->repo->id, ['name' => 'Archive 1']);
    $chosen = qf_location($this->repo->id, ['name' => 'Archive 2']);
    $box = pa05_box('NRA', '5', null, ['location_id' => $boxLocation->id]);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id, 'to_location_id' => $chosen->id])->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBe($chosen->id)
        ->and($box->fresh()->location_id)->toBe($boxLocation->id);
});

it('takes the location its documents share when the target box has none of its own', function (): void {
    $shared = qf_location($this->repo->id, ['name' => 'Archive 3']);
    $box = pa05_box('NRA', '5');
    pa05_doc(['current_box_id' => $box->id, 'location_id' => $shared->id]);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id])->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBe($shared->id);
});

it('clears the location when neither the box nor the form gives one, and the box rule follows', function (): void {
    $old = qf_location($this->repo->id, ['name' => 'Conservation']);
    $from = pa05_box('NRA', '12');
    $to = pa05_box('NRA', '5');
    $doc = pa05_doc(['current_box_id' => $from->id, 'location_id' => $old->id]);
    // The old box took the location of its one document (Box::syncLocationFromDocuments).
    expect($from->fresh()->location_id)->toBe($old->id);

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $to->id])->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBeNull()
        ->and($to->fresh()->location_id)->toBeNull()
        ->and($from->fresh()->location_id)->toBeNull();
});

it('refuses destroyed and PERM_OUT targets on the server, with nothing written', function (array $attrs): void {
    $box = pa05_box('NRA', '5', null, $attrs);
    $doc = pa05_doc(['custody_status' => 'not_in_box']);

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id])
        ->assertNotified('Cannot move — target box is not assignable');

    expect($doc->fresh()->current_box_id)->toBeNull()
        ->and($doc->fresh()->custody_status)->toBe('not_in_box')
        ->and(BoxMovement::query()->count())->toBe(0);
})->with([
    'destroyed' => [fn () => ['destroyed_at' => now()]],
    'perm out' => [['barcode_status' => 'PERM_OUT']],
]);

it('refuses another repository\'s box per row, for a privileged user, batched or not', function (bool $batched): void {
    $foreign = $batched
        ? pa05_box('RAS', '1', pa05_batch('8', $this->otherRepo))
        : pa05_box('NRA', '5', null, [], $this->otherRepo);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $foreign->id]);

    expect($doc->fresh()->current_box_id)->toBeNull()
        ->and(BoxMovement::query()->count())->toBe(0);
})->with(['batched' => true, 'batch-less' => false]);

it('refuses a box of no repository, whose tenancy cannot be vouched for', function (): void {
    $box = Box::withoutGlobalScopes()->create(['box_type' => 'MAV', 'box_number' => '1', 'is_legacy' => true]);
    Box::withoutGlobalScopes()->whereKey($box->id)->update(['repository_id' => null]);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id]);

    expect($doc->fresh()->current_box_id)->toBeNull();
});

it('shows an editor only the boxes and locations of his repository, and refuses forged ones', function (): void {
    $mine = pa05_box('NRA', '5');
    $foreignBox = pa05_box('NRA', '5', null, [], $this->otherRepo);
    $foreignBatched = pa05_box('RAS', '5', pa05_batch('8', $this->otherRepo));
    $foreignLocation = qf_location($this->otherRepo->id, ['name' => 'Foreign archive']);
    $doc = pa05_doc();
    $this->actingAs(bl_actor('editor', $this->repo));

    $lw = pa05_list()->mountTableBulkAction('bulkMoveToBox', [$doc]);
    $picker = pa05_field($lw, 'to_box_id');
    expect(array_keys($picker->getOptions()))->toBe([$mine->id])
        ->and(array_keys($picker->getSearchResults('5')))->toBe([$mine->id]);
    expect(pa05_field($lw, 'to_location_id')->getOptions())->not->toHaveKey($foreignLocation->id);

    // The other repository's box does not even exist for him: no label, not a valid option.
    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $foreignBox->id])
        ->assertHasFormErrors(['to_box_id']);
    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $foreignBatched->id])
        ->assertHasFormErrors(['to_box_id']);
    expect($doc->fresh()->current_box_id)->toBeNull();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $mine->id, 'to_location_id' => $foreignLocation->id]);
    expect($doc->fresh()->current_box_id)->toBeNull()
        ->and($doc->fresh()->location_id)->toBeNull();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $mine->id])->assertHasNoFormErrors();
    expect($doc->fresh()->current_box_id)->toBe($mine->id);
});

it('still moves with the row action of the list', function (): void {
    $box = pa05_box('NRA', '5');
    $doc = pa05_doc();

    pa05_list()->callTableAction('rowMoveToBox', $doc, data: ['to_box_id' => $box->id])->assertHasNoFormErrors();

    expect($doc->fresh()->current_box_id)->toBe($box->id);
});

it('starts empty for a single document too, so a move takes the location of the box', function (): void {
    $old = qf_location($this->repo->id, ['name' => 'Conservation']);
    $boxLocation = qf_location($this->repo->id, ['name' => 'Archive 1']);
    $box = pa05_box('NRA', '5', null, ['location_id' => $boxLocation->id]);
    $doc = pa05_doc(['location_id' => $old->id, 'custody_status' => 'not_in_box']);

    pa05_list()->mountTableAction('rowMoveToBox', $doc)
        ->assertSchemaStateSet(['to_location_id' => null])
        ->setTableActionData(['to_box_id' => $box->id])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBe($boxLocation->id)
        ->and($doc->fresh()->custody_status)->toBe('in_box');
});

it('refuses a non-wills document into a Batch 50 box: the model invariant stays (option 15)', function (): void {
    $b50 = pa05_batch('50');
    $box = pa05_box('RAS', '1', $b50);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id]);

    expect($doc->fresh()->current_box_id)->toBeNull();

    $wills = Series::factory()->create(['code' => 'RWL', 'is_wills_series' => true]);
    $doc->update(['series_id' => $wills->id]);
    pa05_list()->callTableBulkAction('bulkMoveToBox', [$doc], data: ['to_box_id' => $box->id]);

    expect($doc->fresh()->current_box_id)->toBe($box->id)
        ->and($doc->fresh()->batch_id)->toBe($b50->id);
});

// ---------------------------------------------------------------------------
// Option 2: Move out of box.
// ---------------------------------------------------------------------------

it('moves documents out of their box to a custody status and a location, bulk', function (): void {
    $b4 = pa05_batch('4');
    $box = pa05_box('RAS', '192', $b4);
    $location = qf_location($this->repo->id, ['name' => 'Conservation']);
    $doc = pa05_doc(['current_box_id' => $box->id, 'batch_id' => $b4->id]);

    pa05_list()->callTableBulkAction('bulkSetCustodyStatus', [$doc], data: ['custody_status' => 'conservation_box', 'to_location_id' => $location->id])
        ->assertHasNoFormErrors();

    $fresh = $doc->fresh();
    expect($fresh->custody_status)->toBe('conservation_box')
        ->and($fresh->location_id)->toBe($location->id)
        ->and($fresh->current_box_id)->toBeNull()
        ->and($fresh->batch_id)->toBe($b4->id);

    $move = BoxMovement::query()->where('document_id', $doc->id)->sole();
    expect($move->from_box_id)->toBe($box->id)
        ->and($move->to_box_id)->toBeNull()
        ->and($move->repository_id)->toBe($this->repo->id);

    Livewire::test(BoxMovementsRelationManager::class, ['ownerRecord' => $doc, 'pageClass' => ViewDocument::class])
        ->assertOk()
        ->assertSee('Out of box');
});

it('moves out of box from a document page too', function (): void {
    $box = pa05_box('NRA', '5');
    $location = qf_location($this->repo->id);
    $doc = pa05_doc(['current_box_id' => $box->id]);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->callAction('setCustodyStatus', data: ['custody_status' => 'mounted_no_box', 'to_location_id' => $location->id])
        ->assertHasNoFormErrors();

    expect($doc->fresh()->custody_status)->toBe('mounted_no_box')
        ->and($doc->fresh()->current_box_id)->toBeNull();
});

it('writes no movement for a document that was in no box', function (): void {
    $location = qf_location($this->repo->id);
    $doc = pa05_doc(['custody_status' => 'not_in_box']);

    pa05_list()->callTableBulkAction('bulkSetCustodyStatus', [$doc], data: ['custody_status' => 'mounted_no_box', 'to_location_id' => $location->id])->assertHasNoFormErrors();

    expect($doc->fresh()->custody_status)->toBe('mounted_no_box')
        ->and(BoxMovement::query()->count())->toBe(0);
});

it('offers every custody status but In box, and requires both fields of Move out of box', function (): void {
    $doc = pa05_doc();
    $lw = pa05_list()->mountTableBulkAction('bulkSetCustodyStatus', [$doc]);

    expect(pa05_field($lw, 'custody_status')->getOptions())->toBe(['not_in_box' => 'Not in box', 'mounted_no_box' => 'Mounted; no box', 'conservation_box' => 'Conservation Box']);

    $lw->callMountedTableBulkAction()->assertHasFormErrors(['custody_status' => 'required', 'to_location_id' => 'required']);
});

it('refuses In box and another repository\'s location in Move out of box, on the server', function (): void {
    $box = pa05_box('NRA', '5');
    $location = qf_location($this->repo->id);
    $foreign = qf_location($this->otherRepo->id);
    $doc = pa05_doc(['current_box_id' => $box->id]);

    pa05_list()->callTableBulkAction('bulkSetCustodyStatus', [$doc], data: ['custody_status' => 'in_box', 'to_location_id' => $location->id]);
    expect($doc->fresh()->current_box_id)->toBe($box->id);

    pa05_list()->callTableBulkAction('bulkSetCustodyStatus', [$doc], data: ['custody_status' => 'not_in_box', 'to_location_id' => $foreign->id]);
    expect($doc->fresh()->current_box_id)->toBe($box->id)
        ->and($doc->fresh()->custody_status)->toBe('in_box')
        ->and(BoxMovement::query()->count())->toBe(0);
});

it('lists an editor only the active locations of his repository in Move out of box', function (): void {
    $mine = qf_location($this->repo->id);
    $inactive = qf_location($this->repo->id, ['is_active' => false]);
    $global = qf_location(null);
    $foreign = qf_location($this->otherRepo->id);
    $doc = pa05_doc();
    $this->actingAs(bl_actor('editor', $this->repo));

    $options = pa05_field(pa05_list()->mountTableBulkAction('bulkSetCustodyStatus', [$doc]), 'to_location_id')->getOptions();

    expect(array_keys($options))->toContain($mine->id, $global->id)
        ->not->toContain($inactive->id, $foreign->id);
});

it('lets an editor pick a global location in Move out of box, and write the movement', function (): void {
    $global = qf_location(null, ['name' => 'Shared lab']);
    $box = pa05_box('NRA', '5');
    $doc = pa05_doc(['current_box_id' => $box->id]);
    $this->actingAs(bl_actor('editor', $this->repo));

    pa05_list()->callTableBulkAction('bulkSetCustodyStatus', [$doc], data: ['custody_status' => 'not_in_box', 'to_location_id' => $global->id])
        ->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBe($global->id)
        ->and(BoxMovement::withoutGlobalScopes()->where('document_id', $doc->id)->sole()->to_box_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Options 4 and 15: removed.
// ---------------------------------------------------------------------------

it('no longer offers Move to batch or Move to wills', function (): void {
    pa05_list()
        ->assertTableBulkActionDoesNotExist('bulkMoveToBatch')
        ->assertTableBulkActionDoesNotExist('bulkMoveToWills')
        ->assertTableBulkActionExists('bulkMoveToBox')
        ->assertTableBulkActionExists('bulkSetCustodyStatus');

    $names = array_map(fn ($a) => $a->getName(), DocumentActionGroup::singleHeaderActions());
    expect($names)->not->toContain('moveToBatch', 'moveToWills')->toContain('moveToBox', 'setCustodyStatus');
    expect(class_exists('App\Filament\Actions\Documents\MoveToBatchAction'))->toBeFalse()
        ->and(class_exists('App\Filament\Actions\Documents\MoveToWillsAction'))->toBeFalse();
});

it('sends people to a box under Batch 50 from Set subseries, not to Move to wills', function (): void {
    $wills = Series::factory()->create(['code' => 'RWL', 'is_wills_series' => true, 'title' => 'Wills']);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkSetSeries', [$doc], data: ['to_series_id' => $wills->id])
        ->assertNotified("1 document(s) reclassified into 'RWL' — these documents may belong in Batch 50 (wills). Create a box under Batch 50 and use 'Move to box'.");
});

// ---------------------------------------------------------------------------
// Option 6: Send to museum.
// ---------------------------------------------------------------------------

it('sends a document in a box to the museum: location, reference, custody, museum location, out of its box', function (): void {
    $b4 = pa05_batch('4');
    $box = pa05_box('RAS', '192', $b4);
    $showcase = qf_location($this->repo->id, ['name' => 'Showcase 1', 'type' => 'museum', 'code' => 'MUSE-1-7']);
    $doc = pa05_doc(['current_box_id' => $box->id, 'batch_id' => $b4->id]);

    pa05_list()->callTableBulkAction('bulkSetMuseumLocation', [$doc], data: [
        'to_location_id' => $showcase->id, 'museum_reference' => 'EXH-14', 'custody_status' => 'mounted_no_box',
    ])->assertHasNoFormErrors();

    $fresh = $doc->fresh();
    expect($fresh->location_id)->toBe($showcase->id)
        ->and($fresh->museum_reference)->toBe('EXH-14')
        ->and($fresh->custody_status)->toBe('mounted_no_box')
        ->and($fresh->museum_location)->toBe('MUSE-1-7')
        ->and($fresh->current_box_id)->toBeNull()
        ->and($fresh->batch_id)->toBe($b4->id);

    $move = BoxMovement::query()->where('document_id', $doc->id)->sole();
    expect($move->from_box_id)->toBe($box->id)->and($move->to_box_id)->toBeNull();
});

it('writes no movement when the document sent to the museum was in no box', function (): void {
    $showcase = qf_location($this->repo->id, ['type' => 'showcase']);
    $doc = pa05_doc(['custody_status' => 'not_in_box']);

    pa05_list()->callTableBulkAction('bulkSetMuseumLocation', [$doc], data: [
        'to_location_id' => $showcase->id, 'museum_reference' => 'EXH-15', 'custody_status' => 'not_in_box',
    ])->assertHasNoFormErrors();

    expect($doc->fresh()->location_id)->toBe($showcase->id)
        ->and(BoxMovement::query()->count())->toBe(0);
});

it('lists a non-admin only the active museum locations of his repository, with no tenant leak', function (): void {
    $mine = qf_location($this->repo->id, ['name' => 'Showcase 1', 'type' => 'museum']);
    $showcase = qf_location($this->repo->id, ['name' => 'Showcase legacy', 'type' => 'showcase']);
    $archive = qf_location($this->repo->id, ['name' => 'Archive 1', 'type' => 'repository']);
    $inactive = qf_location($this->repo->id, ['name' => 'Old showcase', 'type' => 'museum', 'is_active' => false]);
    $foreign = qf_location($this->otherRepo->id, ['name' => 'Their showcase', 'type' => 'museum']);
    $doc = pa05_doc();
    $this->actingAs(bl_actor('editor', $this->repo));

    $lw = pa05_list()->mountTableBulkAction('bulkSetMuseumLocation', [$doc]);
    $field = pa05_field($lw, 'to_location_id');

    expect(array_keys($field->getOptions()))->toEqualCanonicalizing([$mine->id, $showcase->id])
        ->and($field->getSearchResults('showcase'))->not->toHaveKey($foreign->id)
        ->and($field->getSearchResults('Archive'))->not->toHaveKey($archive->id)
        ->and($field->getSearchResults('Old'))->not->toHaveKey($inactive->id);
    expect(array_keys(pa05_field($lw, 'custody_status')->getOptions()))->toBe(['not_in_box', 'mounted_no_box']);
});

it('requires a custody status for the museum and refuses others, and foreign locations, on the server', function (): void {
    $showcase = qf_location($this->repo->id, ['type' => 'museum']);
    $foreign = qf_location($this->otherRepo->id, ['type' => 'museum']);
    $doc = pa05_doc();

    pa05_list()->callTableBulkAction('bulkSetMuseumLocation', [$doc], data: ['to_location_id' => $showcase->id, 'museum_reference' => 'X'])
        ->assertHasFormErrors(['custody_status' => 'required']);
    pa05_list()->callTableBulkAction('bulkSetMuseumLocation', [$doc], data: ['to_location_id' => $showcase->id, 'museum_reference' => 'X', 'custody_status' => 'in_box']);
    pa05_list()->callTableBulkAction('bulkSetMuseumLocation', [$doc], data: ['to_location_id' => $foreign->id, 'museum_reference' => 'X', 'custody_status' => 'not_in_box']);

    expect($doc->fresh()->location_id)->toBeNull()
        ->and($doc->fresh()->museum_reference)->toBeNull();
});

// ---------------------------------------------------------------------------
// Option 9: Subseries.
// ---------------------------------------------------------------------------

it('reads the Subseries rule as code and title, and searches both (client: "Can I see the code and the title?")', function (): void {
    $reg = Series::factory()->create(['code' => 'REGX', 'title' => 'Registers of acts']);
    Series::factory()->create(['code' => 'MDV', 'title' => 'Medieval']);

    $constraint = DocumentRules::series()->model(Document::class);
    $operator = $constraint->getOperators()['isRelatedTo'];
    $operator->constraint($constraint);
    $select = $operator->getFormSchema()[0];

    expect($select->isMultiple())->toBeTrue()
        ->and($select->getSearchResults('REGX'))->toBe([$reg->id => 'REGX: Registers of acts'])
        ->and($select->getSearchResults('registers'))->toBe([$reg->id => 'REGX: Registers of acts'])
        ->and($select->getOptionLabelFromRecord($reg))->toBe('REGX: Registers of acts');

    // The rule filters as it did.
    $inReg = pa05_doc(['series_id' => $reg->id]);
    $other = pa05_doc();
    pa05_list()->filterTable('queryBuilder', doc_rule('series', 'isRelatedTo', ['value' => [$reg->id]]))
        ->assertCanSeeTableRecords([$inReg])
        ->assertCanNotSeeTableRecords([$other]);
});

it('reads Set subseries the way the list column does', function (): void {
    $reg = Series::factory()->create(['code' => 'REGY', 'title' => 'Registers']);

    $lw = pa05_list()->mountTableBulkAction('bulkSetSeries', [pa05_doc()]);

    expect(pa05_field($lw, 'to_series_id')->getSearchResults('REGY'))->toBe([$reg->id => 'REGY: Registers']);
});

// ---------------------------------------------------------------------------
// Option 10: Set document type.
// ---------------------------------------------------------------------------

it('lists document types by name, holding what a document stores, and links the type', function (): void {
    $coded = DocumentType::create(['name' => 'Bastardello', 'identifier' => 'DT00001', 'is_active' => true]);
    $plain = DocumentType::create(['name' => 'Parcels', 'is_active' => true]);
    DocumentType::create(['name' => 'Retired', 'identifier' => 'DT00099', 'is_active' => false]);
    $doc = pa05_doc(['document_type' => 'Parcels']);

    $lw = pa05_list()->mountTableBulkAction('bulkUpdateDocumentType', [$doc]);
    expect(pa05_field($lw, 'document_type')->getOptions())->toBe(['DT00001' => 'Bastardello', 'Parcels' => 'Parcels']);
    expect(pa05_field($lw, 'document_type')->isSearchable())->toBeTrue();

    $lw->setTableBulkActionData(['document_type' => 'DT00001'])->callMountedTableBulkAction()
        ->assertHasNoFormErrors()
        ->assertNotified("1 document(s) updated to document type 'Bastardello'");

    $fresh = $doc->fresh();
    expect($fresh->document_type)->toBe('DT00001')
        ->and($fresh->document_type_id)->toBe($coded->id)
        ->and($plain->storedValue())->toBe('Parcels');

    pa05_list()->assertTableColumnFormattedStateSet('document_type', 'Bastardello', $fresh);
});

it('takes no free-typed document type and no inactive one', function (string $value): void {
    DocumentType::create(['name' => 'Retired', 'identifier' => 'DT00099', 'is_active' => false]);
    $doc = pa05_doc(['document_type' => 'Parcels']);

    pa05_list()->callTableBulkAction('bulkUpdateDocumentType', [$doc], data: ['document_type' => $value]);

    expect($doc->fresh()->document_type)->toBe('Parcels');
})->with(['Something new', 'DT00099']);

// ---------------------------------------------------------------------------
// Option 11: Add authority, and the label wherever an authority is picked.
// ---------------------------------------------------------------------------

it('shows Citing Reference Code: Authorised form in the authority pickers, listed by surname', function (): void {
    $b = pa05_authority('R16', 'Psaila, Giuseppe Raimondo, 1831-1878', ['given_names' => 'Giuseppe Raimondo']);
    $a = pa05_authority('R305', 'Abela, Nicola', ['given_names' => 'Nicola']);
    $doc = pa05_doc();

    $lw = pa05_list()->mountTableBulkAction('bulkAssignAuthority', [$doc]);
    $select = pa05_field($lw, 'authority_id');

    expect($select->getOptions())->toBe([$a->id => 'R305: Abela, Nicola', $b->id => 'R16: Psaila, Giuseppe Raimondo, 1831-1878']);

    // By code, surname, given name and the authorised form.
    foreach (['R16', 'Psaila', 'Raimondo', '1831-1878'] as $search) {
        expect($select->getSearchResults($search))->toBe([$b->id => 'R16: Psaila, Giuseppe Raimondo, 1831-1878']);
    }

    // The selected value reads the same.
    $lw->setTableBulkActionData(['authority_id' => $b->id]);
    expect(pa05_field($lw, 'authority_id')->getOptionLabel())->toBe('R16: Psaila, Giuseppe Raimondo, 1831-1878');

    $lw->callMountedTableBulkAction()->assertHasNoFormErrors()
        ->assertNotified("Attached 'R16: Psaila, Giuseppe Raimondo, 1831-1878' to 1 document(s)");
    expect($doc->authorities()->pluck('authorities.id')->all())->toBe([$b->id]);
});

it('reads the selected values of the Authorities field of a document the same way', function (): void {
    $a = pa05_authority('R16', 'Psaila, Giuseppe');
    $doc = pa05_doc();
    $doc->authorities()->attach($a->id);

    $lw = Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()]);
    $field = $lw->instance()->getSchema('form')->getFlatFields(withHidden: true)['authorities'];

    expect(array_values($field->getOptionLabels()))->toBe(['R16: Psaila, Giuseppe'])
        ->and($field->getSearchResults('Psaila'))->toBe([$a->id => 'R16: Psaila, Giuseppe']);
});

// ---------------------------------------------------------------------------
// Option 12: Replace authority.
// ---------------------------------------------------------------------------

it('does not open Replace authority when the ticked documents have no authority in common', function (): void {
    $a = pa05_authority('R1', 'Abela, A');
    $b = pa05_authority('R2', 'Borg, B');
    $d1 = pa05_doc();
    $d1->authorities()->attach($a->id);
    $d2 = pa05_doc();
    $d2->authorities()->attach($b->id);

    pa05_list()->mountTableBulkAction('bulkReplaceAuthority', [$d1, $d2])
        ->assertTableBulkActionNotMounted('bulkReplaceAuthority')
        ->assertNotified('Replace authority is not available');
});

it('does not open Replace authority when a ticked document has no authority at all', function (): void {
    $a = pa05_authority('R1', 'Abela, A');
    $d1 = pa05_doc();
    $d1->authorities()->attach($a->id);

    pa05_list()->mountTableBulkAction('bulkReplaceAuthority', [$d1, pa05_doc()])
        ->assertTableBulkActionNotMounted('bulkReplaceAuthority')
        ->assertNotified('Replace authority is not available');
});

it('preselects the one authority common to the ticked documents and leaves it out of the new ones', function (): void {
    $common = pa05_authority('R1', 'Abela, A');
    $other = pa05_authority('R2', 'Borg, B');
    $new = pa05_authority('R3', 'Camilleri, C');
    $d1 = pa05_doc();
    $d1->authorities()->attach([$common->id, $other->id]);
    $d2 = pa05_doc();
    $d2->authorities()->attach($common->id);

    $lw = pa05_list()->mountTableBulkAction('bulkReplaceAuthority', [$d1, $d2])
        ->assertTableBulkActionMounted('bulkReplaceAuthority')
        ->assertSchemaStateSet(['old_authority_id' => $common->id]);

    expect(pa05_field($lw, 'old_authority_id')->getOptions())->toBe([$common->id => 'R1: Abela, A']);
    expect(pa05_field($lw, 'new_authority_id')->getOptions())->toHaveKeys([$other->id, $new->id])
        ->not->toHaveKey($common->id)
        ->and(pa05_field($lw, 'new_authority_id')->getSearchResults('Abela'))->toBe([])
        ->and(pa05_field($lw, 'new_authority_id')->getSearchResults('Camilleri'))->toBe([$new->id => 'R3: Camilleri, C']);
});

it('lets the operator choose among several common authorities, none preselected', function (): void {
    $a = pa05_authority('R1', 'Abela, A');
    $b = pa05_authority('R2', 'Borg, B');
    $d1 = pa05_doc();
    $d1->authorities()->attach([$a->id, $b->id]);
    $d2 = pa05_doc();
    $d2->authorities()->attach([$a->id, $b->id]);

    $lw = pa05_list()->mountTableBulkAction('bulkReplaceAuthority', [$d1, $d2])
        ->assertSchemaStateSet(['old_authority_id' => null]);

    expect(pa05_field($lw, 'old_authority_id')->getOptions())->toBe([$a->id => 'R1: Abela, A', $b->id => 'R2: Borg, B']);
});

it('works out the common authorities for "select all" as well', function (): void {
    $common = pa05_authority('R1', 'Abela, A');
    $d1 = pa05_doc();
    $d1->authorities()->attach($common->id);
    $d2 = pa05_doc();
    $d2->authorities()->attach($common->id);
    $d3 = pa05_doc();

    $lw = pa05_list();
    $lw->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [(string) $d3->id])
        ->mountAction(TestAction::make('bulkReplaceAuthority')->table()->bulk())
        ->assertSchemaStateSet(['old_authority_id' => $common->id]);

    $all = pa05_list();
    $all->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [])
        ->mountAction(TestAction::make('bulkReplaceAuthority')->table()->bulk())
        ->assertActionNotMounted(TestAction::make('bulkReplaceAuthority')->table()->bulk())
        ->assertNotified('Replace authority is not available');
});

it('replaces the authority, keeps is_primary and keeps track of the old one', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $new = pa05_authority('R3', 'Camilleri, C');
    $primary = pa05_doc();
    $primary->authorities()->attach($old->id, ['is_primary' => true]);
    $secondary = pa05_doc();
    $secondary->authorities()->attach($old->id, ['is_primary' => false]);

    pa05_list()->callTableBulkAction('bulkReplaceAuthority', [$primary, $secondary], data: ['old_authority_id' => $old->id, 'new_authority_id' => $new->id])
        ->assertHasNoFormErrors()
        ->assertNotified("Replaced 'R1: Abela, A' with 'R3: Camilleri, C' on 2 document(s)");

    expect((bool) $primary->authorities()->sole()->pivot->is_primary)->toBeTrue()
        ->and((bool) $secondary->authorities()->sole()->pivot->is_primary)->toBeFalse()
        ->and($primary->authorities()->sole()->id)->toBe($new->id);

    $row = $primary->pastAuthorities()->sole();
    expect($row->previous_authority_id)->toBe($old->id)
        ->and($row->reason)->toBe('Replaced by R3: Camilleri, C');
});

it('refuses the submit when a ticked document lost the authority since the form opened', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $new = pa05_authority('R3', 'Camilleri, C');
    $keeps = pa05_doc();
    $keeps->authorities()->attach($old->id);
    $loses = pa05_doc();
    $loses->authorities()->attach($old->id);

    $lw = pa05_list()->mountTableBulkAction('bulkReplaceAuthority', [$keeps, $loses]);
    $loses->authorities()->detach($old->id);

    $lw->setTableBulkActionData(['new_authority_id' => $new->id])->callMountedTableBulkAction()
        ->assertHasFormErrors(['old_authority_id']);

    expect($keeps->authorities()->sole()->id)->toBe($old->id)
        ->and($keeps->pastAuthorities()->count())->toBe(0);
});

it('refuses the same authority as old and new', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $doc = pa05_doc();
    $doc->authorities()->attach($old->id);

    pa05_list()->callTableBulkAction('bulkReplaceAuthority', [$doc], data: ['old_authority_id' => $old->id, 'new_authority_id' => $old->id])
        ->assertHasFormErrors(['new_authority_id']);

    expect($doc->authorities()->sole()->id)->toBe($old->id)
        ->and($doc->pastAuthorities()->count())->toBe(0);
});

it('hands the primary role over when the document already has the new authority', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $new = pa05_authority('R3', 'Camilleri, C');
    $doc = pa05_doc();
    $doc->authorities()->attach($old->id, ['is_primary' => true]);
    $doc->authorities()->attach($new->id, ['is_primary' => false]);

    pa05_list()->callTableBulkAction('bulkReplaceAuthority', [$doc], data: ['old_authority_id' => $old->id, 'new_authority_id' => $new->id])->assertHasNoFormErrors();

    expect($doc->authorities()->sole()->id)->toBe($new->id)
        ->and((bool) $doc->authorities()->sole()->pivot->is_primary)->toBeTrue();
});

it('offers the single-record Replace authority on the authorities of that document, and refuses without any', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $new = pa05_authority('R3', 'Camilleri, C');
    $doc = pa05_doc();
    $doc->authorities()->attach($old->id, ['is_primary' => true]);
    $none = pa05_doc();

    Livewire::test(ViewDocument::class, ['record' => $none->getRouteKey()])
        ->mountAction('replaceAuthority')
        ->assertActionNotMounted('replaceAuthority')
        ->assertNotified('Replace authority is not available');

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->mountAction('replaceAuthority')
        ->assertActionMounted('replaceAuthority')
        ->assertSchemaStateSet(['old_authority_id' => $old->id])
        ->setActionData(['new_authority_id' => $new->id])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($doc->authorities()->sole()->id)->toBe($new->id)
        ->and($doc->pastAuthorities()->sole()->previous_authority_id)->toBe($old->id);
});

it('keeps track of a detached authority too, and says it by its citing label', function (): void {
    $old = pa05_authority('R1', 'Abela, A');
    $doc = pa05_doc();
    $doc->authorities()->attach($old->id);

    pa05_list()->callTableBulkAction('bulkDetachAuthority', [$doc], data: ['authority_id' => $old->id])
        ->assertHasNoFormErrors()
        ->assertNotified("Detached 'R1: Abela, A' from 1 document(s)");

    expect($doc->authorities()->count())->toBe(0)
        ->and($doc->pastAuthorities()->sole()->previous_authority_id)->toBe($old->id)
        ->and($doc->pastAuthorities()->sole()->reason)->toBe('Detached');
});

it('adds and detaches an authority from a document page (they threw there)', function (): void {
    $a = pa05_authority('R1', 'Abela, A');
    $doc = pa05_doc();

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->callAction('assignAuthority', data: ['authority_id' => $a->id])
        ->assertHasNoFormErrors();
    expect($doc->authorities()->sole()->id)->toBe($a->id);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->callAction('detachAuthority', data: ['authority_id' => $a->id])
        ->assertHasNoFormErrors();
    expect($doc->authorities()->count())->toBe(0);
});

it('hides the new actions from a viewer', function (): void {
    $this->actingAs(bl_actor('viewer', $this->repo));

    pa05_list()->assertTableBulkActionHidden('bulkSetCustodyStatus');
});
