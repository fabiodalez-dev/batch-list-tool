<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\BoxResource\Pages\ViewBox;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Document;
use App\Models\Repository;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/**
 * Client 2026-11-10, Operation 1: Return to RAS, for RAS boxes (new barcode,
 * same batch and number) and for NRA / MUS / MAV / STVC boxes (new batch, RAS
 * type, optional new number), driven through the Boxes table as an operator
 * would.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Before any model boots: the audit observer is registered at boot.
    config(['audit.console' => true]);
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->user = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->user);
    $this->batch = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '7']);
    $this->ras = qf_location($this->repo->id, ['name' => 'RAS', 'type' => 'external']);
    $this->nraLocation = qf_location($this->repo->id, ['name' => 'NRA', 'type' => 'external']);
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(function (): void {
    ($this->undoRepeaterFake)();
});

function rr_ras(array $attrs = []): Box
{
    return Box::factory()->create(array_merge([
        'batch_id' => test()->batch->id,
        'barcode_status' => 'PERM_OUT',
        'location_id' => test()->nraLocation->id,
    ], $attrs));
}

function rr_nra(array $attrs = []): Box
{
    return Box::factory()->create(array_merge([
        'box_type' => 'NRA',
        'box_number' => 'NRA' . random_int(1, 99999),
        'batch_id' => null,
        'repository_id' => test()->repo->id,
        'provenance_unknown' => true,
        'barcode' => null,
        'location_id' => test()->nraLocation->id,
    ], $attrs));
}

function rr_doc(Box $box, array $attrs = []): Document
{
    return Document::factory()->create(array_merge([
        'repository_id' => test()->repo->id,
        'batch_id' => $box->batch_id,
        'current_box_id' => $box->id,
    ], $attrs));
}

/** The last notification sent: [title, body]. */
function rr_notified(): array
{
    $last = Arr::last(session()->get('filament.claimed_notifications', []));

    return [(string) ($last['title'] ?? ''), (string) ($last['body'] ?? '')];
}

/**
 * Open the bulk action on these boxes, fill one row per box and submit.
 *
 * @param list<Box> $boxes
 * @param list<array<string, mixed>> $rows in the order of $boxes
 * @param array<string, mixed> $extra
 */
function rr_return(array $boxes, array $rows, array $extra = [])
{
    $data = $extra;
    foreach ($rows as $i => $row) {
        foreach ($row as $field => $value) {
            $data["boxes.{$i}.{$field}"] = $value;
        }
    }

    return Livewire::test(ListBoxes::class)
        ->mountTableBulkAction('returnBoxesToRas', $boxes)
        ->fillForm($data)
        ->callMountedTableAction();
}

/* ---- RAS boxes ------------------------------------------------------------ */

it('returns a RAS box: new barcode, old one in the history, location RAS, status IN, batch and number kept', function (): void {
    $box = rr_ras(['box_number' => '12', 'barcode' => 'OLD-1']);
    $inBox = rr_doc($box, ['custody_status' => 'in_box', 'location_id' => $this->nraLocation->id]);
    $elsewhere = rr_doc($box, ['custody_status' => 'not_in_box', 'location_id' => $this->nraLocation->id]);

    rr_return([$box], [['barcode' => 'NEW-1']])->assertHasNoFormErrors();

    $fresh = $box->fresh();
    expect($fresh->barcode)->toBe('NEW-1')
        ->and($fresh->barcode_status)->toBe('IN')
        ->and($fresh->location_id)->toBe($this->ras->id)
        ->and($fresh->batch_id)->toBe($this->batch->id)
        ->and($fresh->box_number)->toBe('12')
        ->and($fresh->box_type)->toBe('RAS');

    $history = $fresh->barcodeHistory()->sole();
    expect($history->previous_barcode)->toBe('OLD-1')
        ->and($history->new_barcode)->toBe('NEW-1')
        ->and($history->previous_status)->toBe('PERM_OUT')
        ->and($history->new_status)->toBe('IN')
        ->and($history->reason)->toBe('Returned to RAS')
        ->and($history->changed_by_user_id)->toBe($this->user->id)
        ->and($fresh->previousBarcodes()->all())->toContain('OLD-1');

    $move = $fresh->locationHistory()->where('to_location_id', $this->ras->id)->sole();
    expect($move->from_location_id)->toBe($this->nraLocation->id)
        ->and($move->source)->toBe('return_to_ras');

    // Only what is physically in the box follows it.
    expect($inBox->fresh()->location_id)->toBeNull()
        ->and($inBox->fresh()->barcode_status)->toBe('IN')
        ->and($elsewhere->fresh()->location_id)->toBe($this->nraLocation->id)
        ->and(Audit::query()->where('event', 'document.followed_box')->where('auditable_id', $inBox->id)->exists())->toBeTrue();

    // The box's own audit row records the change.
    $audit = Audit::query()->where('auditable_type', Box::class)->where('auditable_id', $box->id)->where('event', 'updated')->latest('id')->first();
    expect($audit->old_values['barcode'])->toBe('OLD-1')
        ->and($audit->new_values['barcode'])->toBe('NEW-1');

    expect(rr_notified()[0])->toBe('1 box returned to RAS');
});

it('returns a RAS box from the row action of the box page', function (): void {
    $box = rr_ras(['box_number' => '3', 'barcode' => 'OLD-3', 'barcode_status' => 'OUT']);

    Livewire::test(ViewBox::class, ['record' => $box->getKey()])
        ->callAction('returnToRas', data: ['boxes.0.barcode' => 'NEW-3', 'note' => 'Back from NRA'])
        ->assertHasNoFormErrors();

    $fresh = $box->fresh();
    expect($fresh->barcode)->toBe('NEW-3')
        ->and($fresh->barcode_status)->toBe('IN')
        ->and($fresh->location_id)->toBe($this->ras->id)
        ->and($fresh->tracking_note)->toContain('[Return to RAS] Back from NRA');
});

/* ---- NRA / MUS / MAV / STVC boxes ----------------------------------------- */

it('returns NRA boxes into a new batch as RAS boxes, the new box number optional', function (): void {
    $numbered = rr_nra(['box_number' => 'NRA1', 'current_box_type' => null]);
    $doc = rr_doc($numbered, ['batch_id' => null, 'custody_status' => 'in_box', 'location_id' => $this->nraLocation->id]);
    $kept = rr_nra(['box_number' => 'NRA2', 'barcode' => 'NRA-OLD']);

    $page = Livewire::test(ListBoxes::class)->mountTableBulkAction('returnBoxesToRas', [$numbered, $kept]);
    // Prefilled: the next batch number of the repository and the accession type.
    expect($page->instance()->mountedActions[0]['data']['batch_number'])->toBe('8')
        ->and($page->instance()->mountedActions[0]['data']['batch_type'])->toBe('NOTARY_ACCESSION');

    $page->fillForm([
        'boxes.0.barcode' => 'RAS-100',
        'boxes.0.box_number' => '1',
        'boxes.0.current_box_type' => 'Big Brown Box',
        'boxes.1.barcode' => 'RAS-101',
        'batch_number' => '200',
        'batch_description' => 'From NRA, November',
    ])->callMountedTableAction()->assertHasNoFormErrors();

    $batch = Batch::query()->where('batch_number', '200')->sole();
    expect($batch->repository_id)->toBe($this->repo->id)
        ->and($batch->type)->toBe('NOTARY_ACCESSION')
        ->and($batch->description)->toBe('From NRA, November')
        ->and(Audit::query()->where('auditable_type', Batch::class)->where('auditable_id', $batch->id)->where('event', 'created')->exists())->toBeTrue();

    $one = $numbered->fresh();
    expect($one->box_type)->toBe('RAS')
        ->and($one->batch_id)->toBe($batch->id)
        ->and($one->repository_id)->toBeNull()
        ->and($one->box_number)->toBe('1')
        ->and($one->current_box_type)->toBe('Big Brown Box')
        ->and($one->barcode)->toBe('RAS-100')
        ->and($one->barcode_status)->toBe('IN')
        ->and($one->location_id)->toBe($this->ras->id)
        ->and($one->tracking_note)->toContain('[Return to RAS] Was NRA NRA1');

    $two = $kept->fresh();
    expect($two->box_type)->toBe('RAS')
        ->and($two->batch_id)->toBe($batch->id)
        ->and($two->box_number)->toBe('NRA2')
        ->and($two->barcode)->toBe('RAS-101')
        ->and($two->barcodeHistory()->sole()->previous_barcode)->toBe('NRA-OLD')
        ->and($two->barcodeHistory()->sole()->reason)->toBe('Returned to RAS from NRA NRA2, barcode NRA-OLD');

    // The document joins the batch and follows the box to RAS.
    expect($doc->fresh()->batch_id)->toBe($batch->id)
        ->and($doc->fresh()->location_id)->toBeNull()
        ->and(Audit::query()->where('event', 'document.moved_with_box')->where('auditable_id', $doc->id)->exists())->toBeTrue();

    [$title, $body] = rr_notified();
    expect($title)->toBe('2 boxes returned to RAS')
        ->and($body)->toContain('Batch 200 was created for this movement.');
});

it('handles a mixed selection: RAS boxes keep their batch, the others get the new one', function (): void {
    $ras = rr_ras(['box_number' => '5', 'barcode' => 'OLD-5']);
    $mav = Box::factory()->create(['box_type' => 'MAV', 'box_number' => 'MAV9', 'batch_id' => null, 'repository_id' => $this->repo->id, 'is_legacy' => true, 'barcode' => null, 'location_id' => $this->nraLocation->id]);

    rr_return([$ras, $mav], [['barcode' => 'NEW-5'], ['barcode' => 'NEW-MAV']], ['batch_number' => '300'])->assertHasNoFormErrors();

    $batch = Batch::query()->where('batch_number', '300')->sole();
    expect($ras->fresh()->batch_id)->toBe($this->batch->id)
        ->and($mav->fresh()->batch_id)->toBe($batch->id)
        ->and($mav->fresh()->box_type)->toBe('RAS')
        ->and(rr_notified()[0])->toBe('2 boxes returned to RAS');
});

it('does not ask for a batch when every box is a RAS box', function (): void {
    $box = rr_ras();

    rr_return([$box], [['barcode' => 'ONLY-RAS']], ['batch_number' => null])->assertHasNoFormErrors();

    expect(Batch::query()->count())->toBe(1)
        ->and($box->fresh()->barcode)->toBe('ONLY-RAS');
});

/* ---- Validation ----------------------------------------------------------- */

it('requires a barcode for each box', function (): void {
    $box = rr_ras(['barcode' => 'OLD-7']);

    rr_return([$box], [['barcode' => '']])->assertHasFormErrors(['boxes.0.barcode' => 'required']);

    expect($box->fresh()->barcode)->toBe('OLD-7');
});

it('refuses a barcode another box has, the box\'s own, and the same one typed twice', function (): void {
    rr_ras(['barcode' => 'TAKEN']);
    $a = rr_ras(['barcode' => 'OLD-A', 'box_number' => '1']);
    $b = rr_ras(['barcode' => 'OLD-B', 'box_number' => '2']);

    rr_return([$a], [['barcode' => 'TAKEN']])->assertHasFormErrors(['boxes.0.barcode']);
    rr_return([$a], [['barcode' => 'OLD-A']])->assertHasFormErrors(['boxes.0.barcode']);
    rr_return([$a, $b], [['barcode' => 'TWICE'], ['barcode' => 'TWICE']])->assertHasFormErrors(['boxes.0.barcode']);

    expect($a->fresh()->barcode)->toBe('OLD-A')
        ->and($b->fresh()->barcode)->toBe('OLD-B');
});

it('refuses a batch number already used or reserved', function (string $number): void {
    Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '9']);
    $box = rr_nra();

    rr_return([$box], [['barcode' => 'X-1']], ['batch_number' => $number])->assertHasFormErrors(['batch_number']);

    expect($box->fresh()->box_type)->toBe('NRA');
})->with(['taken' => '9', 'forbidden' => '34', 'wills' => '50', 'not a number' => '0']);

it('refuses a new box number already used in the new batch twice', function (): void {
    $a = rr_nra();
    $b = rr_nra();

    rr_return([$a, $b], [['barcode' => 'X-1', 'box_number' => '4'], ['barcode' => 'X-2', 'box_number' => '4']], ['batch_number' => '400'])
        ->assertHasFormErrors(['boxes.0.box_number']);

    expect(Batch::query()->where('batch_number', '400')->exists())->toBeFalse();
});

it('lets several boxes keep their identifiers: blank box numbers are not duplicates', function (): void {
    $a = rr_nra(['box_number' => 'NRA10']);
    $b = rr_nra(['box_number' => 'NRA11']);

    rr_return([$a, $b], [['barcode' => 'K-1'], ['barcode' => 'K-2']], ['batch_number' => '410'])->assertHasNoFormErrors();

    expect($a->fresh()->box_number)->toBe('NRA10')
        ->and($b->fresh()->box_number)->toBe('NRA11')
        ->and($a->fresh()->box_type)->toBe('RAS');
});

/* ---- Refusals and counts -------------------------------------------------- */

it('skips a RAS box already at RAS with status IN and a destroyed box, and counts them', function (): void {
    $done = rr_ras(['box_number' => '1']);
    $already = rr_ras(['box_number' => '2', 'barcode_status' => 'IN', 'location_id' => $this->ras->id, 'barcode' => 'HERE-2']);
    $destroyed = rr_ras(['box_number' => '3', 'destroyed_at' => now(), 'destroyed_reason' => 'Shredded', 'barcode' => 'GONE-3']);

    rr_return([$done, $already, $destroyed], [['barcode' => 'N-1'], ['barcode' => 'N-2'], ['barcode' => 'N-3']])->assertHasNoFormErrors();

    expect($done->fresh()->barcode)->toBe('N-1')
        ->and($already->fresh()->barcode)->toBe('HERE-2')
        ->and($destroyed->fresh()->barcode)->toBe('GONE-3');

    [$title, $body] = rr_notified();
    expect($title)->toBe('1 box returned to RAS, 2 skipped')
        ->and($body)->toContain('Already at RAS with status IN. (1): Batch 7 / Box 2')
        ->and($body)->toContain('The box is destroyed. (1): Batch 7 / Box 3');
});

it('does not offer the row action on a RAS box already IN at RAS, nor on a destroyed box', function (): void {
    $here = rr_ras(['box_number' => '1', 'barcode_status' => 'IN', 'location_id' => $this->ras->id]);
    $destroyed = rr_ras(['box_number' => '2', 'destroyed_at' => now(), 'destroyed_reason' => 'Gone']);
    $away = rr_ras(['box_number' => '3']);

    Livewire::test(ListBoxes::class)
        ->assertTableActionHidden('returnToRas', $here)
        ->assertTableActionHidden('returnToRas', $destroyed)
        ->assertTableActionVisible('returnToRas', $away);
});

it('does not create the batch when every other box is refused', function (): void {
    $destroyed = rr_nra(['destroyed_at' => now(), 'destroyed_reason' => 'Gone']);

    rr_return([$destroyed], [['barcode' => 'D-1']], ['batch_number' => '500'])->assertHasNoFormErrors();

    expect(Batch::query()->where('batch_number', '500')->exists())->toBeFalse()
        ->and($destroyed->fresh()->box_type)->toBe('NRA')
        ->and(rr_notified()[0])->toBe('No box was returned to RAS: 1 skipped');
});

it('stops before touching anything when there is no RAS location', function (): void {
    $this->ras->delete();
    $box = rr_ras(['barcode' => 'OLD-9']);

    rr_return([$box], [['barcode' => 'NEW-9']]);

    expect($box->fresh()->barcode)->toBe('OLD-9')
        ->and(rr_notified()[1])->toContain('no active location named "RAS"');
});

it('refuses more than 50 boxes before opening the form', function (): void {
    $boxes = Box::factory()->count(51)->create(['batch_id' => $this->batch->id]);

    Livewire::test(ListBoxes::class)
        ->mountTableBulkAction('returnBoxesToRas', $boxes)
        ->assertNotified('Too many boxes selected for Return to RAS')
        ->assertSet('mountedActions', []);

    expect(BoxBarcodeHistory::query()->count())->toBe(0);
});

/* ---- Tenancy and permissions ---------------------------------------------- */

it('does not touch a box of another repository through a forged selection', function (): void {
    $other = Repository::factory()->create();
    qf_location($other->id, ['name' => 'RAS', 'type' => 'external']);
    $otherBatch = Batch::withoutGlobalScopes()->create(['repository_id' => $other->id, 'batch_number' => '2', 'is_active' => true]);
    $foreign = Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => '77', 'batch_id' => $otherBatch->id, 'barcode' => 'FOREIGN', 'barcode_status' => 'PERM_OUT']);
    $mine = rr_ras(['barcode' => 'MINE']);

    $this->actingAs(bl_actor('editor', $this->repo));

    Livewire::test(ListBoxes::class)
        ->set('selectedTableRecords', [(string) $foreign->id, (string) $mine->id])
        ->mountTableBulkAction('returnBoxesToRas', [$foreign, $mine])
        ->fillForm(['boxes.0.barcode' => 'MINE-NEW'])
        ->callMountedTableAction();

    expect(Box::withoutGlobalScopes()->find($foreign->id)->barcode)->toBe('FOREIGN')
        ->and($mine->fresh()->barcode)->toBe('MINE-NEW')
        ->and(rr_notified()[0] . rr_notified()[1])->not->toContain('77');
});

it('hides Return to RAS from a viewer', function (): void {
    $this->actingAs(bl_actor('viewer', $this->repo));

    Livewire::test(ListBoxes::class)->assertTableBulkActionHidden('returnBoxesToRas');
});

it('refuses the other box types to a user who may not create batches', function (): void {
    $editor = bl_actor('editor', $this->repo);
    $editor->revokePermissionTo('create_batch');
    foreach ($editor->roles as $role) {
        $role->revokePermissionTo('create_batch');
    }
    $this->actingAs($editor->fresh());
    $box = rr_nra();

    rr_return([$box], [['barcode' => 'E-1']], ['batch_number' => '600']);

    expect($box->fresh()->box_type)->toBe('NRA')
        ->and(Batch::withoutGlobalScopes()->where('batch_number', '600')->exists())->toBeFalse()
        ->and(rr_notified()[1])->toContain('may not create batches');
});
