<?php

declare(strict_types=1);

use App\Filament\Actions\Boxes\MoveBoxToLocationAction;
use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/**
 * Client 2026-11-05, issues 2, 6, 9 and 12 (option 16): the Create Box field
 * order, Relocate boxes, and the Boxes bulk actions, driven through the table
 * of the Boxes page as an operator would.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->user = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->user);
    $this->batch = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '7']);
});

function bb_box(array $attrs = []): Box
{
    return Box::factory()->create(array_merge(['batch_id' => test()->batch->id], $attrs));
}

function bb_doc(Box $box, array $attrs = []): Document
{
    return Document::factory()->create(array_merge([
        'repository_id' => test()->repo->id,
        'batch_id' => $box->batch_id,
        'current_box_id' => $box->id,
    ], $attrs));
}

function bb_location(string $name, string $type = 'external', ?int $repoId = null, array $attrs = []): Location
{
    return qf_location($repoId ?? test()->repo->id, array_merge(['name' => $name, 'type' => $type], $attrs));
}

/** The last notification the action sent: [title, body]. */
function bb_notified(): array
{
    // Filament moves what was sent here at the end of each Livewire request.
    $last = Arr::last(session()->get('filament.claimed_notifications', []));

    return [(string) ($last['title'] ?? ''), (string) ($last['body'] ?? '')];
}

/* ---- Issue 2: Create Box order of fields ---------------------------------- */

it('orders the Box form Box type, Current Box Type, Batch, Box Identifier (issue 2)', function (string $page, array $params): void {
    $box = bb_box();
    $params = $params === [] ? [] : ['record' => $box->getKey()];

    Livewire::test($page, $params)
        ->assertSeeInOrder(['Box type', 'Current Box Type', 'Batch', 'Box number']);
})->with([
    'create' => [CreateBox::class, []],
    'edit' => [EditBox::class, ['record' => true]],
]);

/* ---- Issue 6: Relocate ---------------------------------------------------- */

it('offers only Repository and Room locations to relocate (issue 6)', function (): void {
    bb_location('Archive 1', 'repository');
    bb_location('Cataloguing', 'room');
    bb_location('Showcase 1', 'museum');
    bb_location('RAS', 'external');

    $page = Livewire::test(ListBoxes::class)->mountTableBulkAction('relocate', [bb_box()]);
    $select = $page->instance()->getSchema('mountedActionSchema0')->getFlatFields()['location_id'];

    expect(array_values($select->getOptions()))->toEqualCanonicalizing(['Archive 1', 'Cataloguing'])
        ->and(array_values($select->getSearchResults('s')))->not->toContain('Showcase 1', 'RAS');
});

it('refuses a forged post with a location of another type (issue 6)', function (): void {
    $museum = bb_location('Showcase 1', 'museum');
    $box = bb_box();

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$box], data: ['location_id' => $museum->id, 'tracking_note' => 'x']);

    expect($box->fresh()->location_id)->toBeNull()
        ->and($box->fresh()->tracking_note)->toBeNull();
});

it('joins the relocate note to an existing tracking note with "; " (issue 6)', function (): void {
    $archive = bb_location('Archive 1', 'repository');
    $noted = bb_box(['tracking_note' => 'First']);
    $empty = bb_box(['tracking_note' => null]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$noted, $empty], data: ['location_id' => $archive->id, 'tracking_note' => 'Second']);

    expect($noted->fresh()->tracking_note)->toBe('First; Second')
        ->and($empty->fresh()->tracking_note)->toBe('Second');
});

it('joins the row action move reason with "; " too (issue 6)', function (): void {
    $archive = bb_location('Archive 1', 'repository');
    $box = bb_box(['tracking_note' => 'First']);

    Livewire::test(ListBoxes::class)
        ->callTableAction(MoveBoxToLocationAction::make()->getName(), $box, data: ['to_location_id' => $archive->id, 'reason' => 'Why']);

    expect($box->fresh()->tracking_note)->toBe('First; [Move] Why');
});

/* ---- Issue 9: Destroy boxes ----------------------------------------------- */

it('destroys the eligible boxes and names the ones skipped (issue 9)', function (): void {
    $ok = bb_box(['box_number' => '1']);
    bb_doc($ok, ['catalogue_identifier' => 'CAT-1']);
    $blocked = bb_box(['box_number' => '2']);
    bb_doc($blocked, ['catalogue_identifier' => null]);
    $already = bb_box(['box_number' => '3', 'destroyed_at' => now(), 'destroyed_reason' => 'before']);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('destroyBoxes', [$ok, $blocked, $already], data: ['reason' => 'Shredded']);

    expect($ok->fresh()->isDestroyed())->toBeTrue()
        ->and($ok->fresh()->destroyed_reason)->toBe('Shredded')
        ->and($ok->fresh()->destroyed_by_user_id)->toBe($this->user->id)
        ->and($blocked->fresh()->isDestroyed())->toBeFalse()
        ->and($already->fresh()->destroyed_reason)->toBe('before');

    [$title, $body] = bb_notified();
    expect($title)->toBe('1 box marked as destroyed, 2 skipped')
        ->and($body)->toContain('Box 2')->toContain('uncatalogued')
        ->and($body)->toContain('Box 3')->toContain('already marked destroyed');
});

/* ---- Issue 9: Change batch ------------------------------------------------ */

it('moves the box and the documents in it to the new batch, with an audit trail (issue 9)', function (): void {
    $target = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '8']);
    $box = bb_box();
    $doc = bb_doc($box);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('changeBatch', [$box], data: ['to_batch_id' => $target->id]);

    expect($box->fresh()->batch_id)->toBe($target->id)
        ->and($doc->fresh()->batch_id)->toBe($target->id);

    $audit = Audit::query()->where('event', 'document.moved_with_box')->where('auditable_id', $doc->id)->sole();
    expect($audit->old_values['batch_id'])->toBe($this->batch->id)
        ->and($audit->new_values['batch_id'])->toBe($target->id)
        ->and(bb_notified()[0])->toBe('1 box moved to batch 8');
});

it('refuses a box whose type and number the batch already has, and names it (issue 9)', function (): void {
    $target = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => '8']);
    bb_box(['batch_id' => $target->id, 'box_number' => '5']);
    $clashing = bb_box(['box_number' => '5']);
    $doc = bb_doc($clashing);
    $free = bb_box(['box_number' => '6']);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('changeBatch', [$clashing, $free], data: ['to_batch_id' => $target->id]);

    expect($clashing->fresh()->batch_id)->toBe($this->batch->id)
        ->and($doc->fresh()->batch_id)->toBe($this->batch->id)
        ->and($free->fresh()->batch_id)->toBe($target->id);

    [$title, $body] = bb_notified();
    expect($title)->toBe('1 box moved to batch 8, 1 skipped')
        ->and($body)->toContain('Batch 7 / Box 5')->toContain('already has a box');
});

it('refuses a batch of another repository for a forged post, for a non-admin (issue 9)', function (): void {
    $other = Repository::factory()->create();
    $foreign = Batch::withoutGlobalScopes()->create(['repository_id' => $other->id, 'batch_number' => '99', 'is_active' => true]);
    $this->actingAs(bl_actor('editor', $this->repo));
    $box = bb_box();

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('changeBatch', [$box], data: ['to_batch_id' => $foreign->id]);

    expect($box->fresh()->batch_id)->toBe($this->batch->id);
});

it('refuses non-wills documents going into Batch 50, per box (issue 9)', function (): void {
    $wills = Batch::factory()->create(['repository_id' => $this->repo->id, 'batch_number' => (string) Batch::WILLS_BATCH]);
    $willsSeries = Series::factory()->create(['is_wills_series' => true, 'repository_id' => $this->repo->id]);
    $plainSeries = Series::factory()->create(['is_wills_series' => false, 'repository_id' => $this->repo->id]);

    $plain = bb_box(['box_number' => '1']);
    $plainDoc = bb_doc($plain, ['series_id' => $plainSeries->id]);
    $willsBox = bb_box(['box_number' => '2']);
    $willsDoc = bb_doc($willsBox, ['series_id' => $willsSeries->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('changeBatch', [$plain, $willsBox], data: ['to_batch_id' => $wills->id]);

    expect($plain->fresh()->batch_id)->toBe($this->batch->id)
        ->and($plainDoc->fresh()->batch_id)->toBe($this->batch->id)
        ->and($willsBox->fresh()->batch_id)->toBe($wills->id)
        ->and($willsDoc->fresh()->batch_id)->toBe($wills->id)
        ->and(bb_notified()[1])->toContain('reserved for wills')->toContain('Box 1');
});

/* ---- Issue 9: Send to disinfestation -------------------------------------- */

it('sends boxes to disinfestation: documents flagged, location, RAS boxes OUT, others keep theirs (issue 9)', function (): void {
    $target = bb_location('Disinfestation');
    $ras = bb_box(['box_number' => '1']);
    $rasDoc = bb_doc($ras, ['barcode_status' => 'IN']);
    $nra = bb_box(['box_type' => 'NRA', 'box_number' => 'NRA1', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true, 'barcode_status' => 'IN']);
    $nraDoc = bb_doc($nra, ['batch_id' => null]);

    Livewire::test(ListBoxes::class)->callTableBulkAction('sendToDisinfestation', [$ras, $nra]);

    expect($ras->fresh()->barcode_status)->toBe('OUT')
        ->and($ras->fresh()->location_id)->toBe($target->id)
        ->and($rasDoc->fresh()->is_in_disinfestation)->toBeTrue()
        ->and($rasDoc->fresh()->barcode_status)->toBe('OUT')
        ->and($nra->fresh()->barcode_status)->toBe('IN')
        ->and($nra->fresh()->location_id)->toBe($target->id)
        ->and($nraDoc->fresh()->is_in_disinfestation)->toBeTrue();

    $audit = Audit::query()->where('event', 'document.sent_to_disinfestation')->where('auditable_id', $rasDoc->id)->sole();
    expect($audit->new_values['is_in_disinfestation'])->toBeTrue()
        ->and($audit->new_values['barcode_status'])->toBe('OUT')
        ->and($audit->old_values['barcode_status'])->toBe('IN')
        ->and($audit->user_id)->toBe($this->user->id);

    // Box history and the box's own audit row come from the model.
    expect($ras->fresh()->barcodeHistory()->where('new_status', 'OUT')->exists())->toBeTrue()
        ->and($ras->fresh()->locationHistory()->where('to_location_id', $target->id)->exists())->toBeTrue();
});

it('stops before touching anything when there is no Disinfestation location (issue 9)', function (): void {
    bb_location('disinfestation lab');
    $box = bb_box();
    $doc = bb_doc($box);

    Livewire::test(ListBoxes::class)->callTableBulkAction('sendToDisinfestation', [$box]);

    expect($box->fresh()->barcode_status)->toBe('IN')
        ->and($box->fresh()->location_id)->toBeNull()
        ->and($doc->fresh()->is_in_disinfestation)->toBeFalse()
        ->and(bb_notified()[1])->toContain('no active location named "Disinfestation"');
});

it('reports a PERM_OUT RAS box as skipped when sending to disinfestation (issue 9)', function (): void {
    bb_location('Disinfestation');
    $box = bb_box(['barcode_status' => 'PERM_OUT', 'box_number' => '4']);
    $doc = bb_doc($box);

    Livewire::test(ListBoxes::class)->callTableBulkAction('sendToDisinfestation', [$box]);

    expect($box->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($box->fresh()->location_id)->toBeNull()
        ->and($doc->fresh()->is_in_disinfestation)->toBeFalse();

    [$title, $body] = bb_notified();
    expect($title)->toContain('1 skipped')->and($body)->toContain('PERM OUT')->toContain('Box 4');
});

it('uses a Disinfestation location only of the box repository or global (issue 9)', function (): void {
    $other = Repository::factory()->create();
    bb_location('Disinfestation', 'external', $other->id);
    $box = bb_box();

    Livewire::test(ListBoxes::class)->callTableBulkAction('sendToDisinfestation', [$box]);

    expect($box->fresh()->location_id)->toBeNull()
        ->and(bb_notified()[0])->toBe('Nothing was sent to disinfestation');
});

/* ---- Issue 9: Finish disinfestation --------------------------------------- */

it('finishes back to RAS: documents dated, flag cleared, status IN, location RAS (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    $ras = bb_location('RAS');
    $box = bb_box(['barcode_status' => 'OUT', 'location_id' => $chamber->id]);
    $doc = bb_doc($box, ['is_in_disinfestation' => true, 'barcode_status' => 'OUT']);
    $nra = bb_box(['box_type' => 'NRA', 'box_number' => 'NRA1', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true, 'barcode_status' => 'OUT', 'location_id' => $chamber->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box, $nra], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras']);

    // Other box types get the location only.
    expect($nra->fresh()->barcode_status)->toBe('OUT')
        ->and($nra->fresh()->location_id)->toBe($ras->id);

    $fresh = $box->fresh();
    expect($fresh->barcode_status)->toBe('IN')
        ->and($fresh->location_id)->toBe($ras->id)
        ->and($fresh->disinfestation_date->toDateString())->toBe('2026-10-01')
        ->and($doc->fresh()->disinfestation_date->toDateString())->toBe('2026-10-01')
        ->and($doc->fresh()->is_in_disinfestation)->toBeFalse()
        ->and($doc->fresh()->barcode_status)->toBe('IN')
        ->and(Audit::query()->where('event', 'document.marked_disinfested')->where('auditable_id', $doc->id)->exists())->toBeTrue();
});

it('names the disinfestation date as dd-mm-yyyy in its notification', function (): void {
    bb_location('RAS');
    $box = bb_box(['barcode_status' => 'OUT', 'location_id' => bb_location('Disinfestation')->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras'])
        ->assertNotified('1 box finished disinfestation on 01-10-2026');
});

it('finishes to an archive location: RAS boxes PERM_OUT, other types the location only (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    $archive = bb_location('Archive 3', 'repository');
    $ras = bb_box(['box_number' => '1', 'barcode_status' => 'OUT', 'location_id' => $chamber->id]);
    $nra = bb_box(['box_type' => 'NRA', 'box_number' => 'NRA1', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true, 'barcode_status' => 'IN', 'location_id' => $chamber->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$ras, $nra], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'archive', 'location_id' => $archive->id]);

    expect($ras->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($ras->fresh()->location_id)->toBe($archive->id)
        ->and($nra->fresh()->barcode_status)->toBe('IN')
        ->and($nra->fresh()->location_id)->toBe($archive->id);
});

it('keeps an earlier date in the first free slot, and refuses a box where it would be lost (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    bb_location('RAS');
    $box = bb_box(['box_number' => '1', 'barcode_status' => 'OUT', 'location_id' => $chamber->id]);
    $doc = bb_doc($box, ['disinfestation_date' => '2020-01-01', 'disinfestation_date_1' => '2019-01-01', 'is_in_disinfestation' => true]);
    $fullBox = bb_box(['box_number' => '2', 'barcode_status' => 'OUT', 'location_id' => $chamber->id]);
    $full = bb_doc($fullBox, [
        'catalogue_identifier' => 'CAT-FULL',
        'disinfestation_date' => '2020-01-01',
        'disinfestation_date_1' => '2017-01-01',
        'disinfestation_date_2' => '2018-01-01',
        'disinfestation_date_3' => '2019-01-01',
        'is_in_disinfestation' => true,
    ]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box, $fullBox], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras']);

    $fresh = $doc->fresh();
    expect($fresh->disinfestation_date->toDateString())->toBe('2026-10-01')
        ->and($fresh->disinfestation_date_1->toDateString())->toBe('2019-01-01')
        ->and($fresh->disinfestation_date_2->toDateString())->toBe('2020-01-01')
        ->and($fresh->disinfestationTimeline()->pluck('date')->map->toDateString()->all())->toBe(['2019-01-01', '2020-01-01', '2026-10-01']);

    // The other box is left as it was, whole.
    expect($full->fresh()->disinfestation_date->toDateString())->toBe('2020-01-01')
        ->and($full->fresh()->is_in_disinfestation)->toBeTrue()
        ->and($fullBox->fresh()->barcode_status)->toBe('OUT')
        ->and($fullBox->fresh()->location_id)->toBe($chamber->id);

    [$title, $body] = bb_notified();
    expect($title)->toContain('1 skipped')->and($body)->toContain('CAT-FULL')->toContain('Box 2');
});

it('refuses a box that is not at the location Disinfestation (issue 9)', function (): void {
    bb_location('RAS');
    $elsewhere = bb_location('Archive 1', 'repository');
    $box = bb_box(['barcode_status' => 'OUT', 'location_id' => $elsewhere->id]);
    $doc = bb_doc($box, ['is_in_disinfestation' => true]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras']);

    expect($box->fresh()->barcode_status)->toBe('OUT')
        ->and($doc->fresh()->is_in_disinfestation)->toBeTrue()
        ->and(bb_notified()[1])->toContain('not at the location Disinfestation');
});

it('reports a PERM_OUT box returning to RAS as skipped: it needs a new barcode (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    bb_location('RAS');
    $box = bb_box(['barcode_status' => 'PERM_OUT', 'location_id' => $chamber->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'ras']);

    expect($box->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($box->fresh()->location_id)->toBe($chamber->id)
        ->and(bb_notified()[1])->toContain('needs a new barcode');
});

it('refuses a future date and a forged archive location of the wrong type (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    $museum = bb_location('Showcase 1', 'museum');
    $box = bb_box(['barcode_status' => 'OUT', 'location_id' => $chamber->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => now()->addDays(2)->toDateString(), 'destination' => 'ras']);
    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'archive', 'location_id' => $museum->id]);

    expect($box->fresh()->location_id)->toBe($chamber->id)
        ->and($box->fresh()->disinfestation_date)->toBeNull();
});

it('does not move a box to the archive location of another repository (issue 9)', function (): void {
    $chamber = bb_location('Disinfestation');
    $foreign = bb_location('Archive 9', 'repository', Repository::factory()->create()->id);
    $box = bb_box(['barcode_status' => 'OUT', 'location_id' => $chamber->id]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('finishDisinfestation', [$box], data: ['disinfestation_date' => '2026-10-01', 'destination' => 'archive', 'location_id' => $foreign->id]);

    expect($box->fresh()->location_id)->toBe($chamber->id);
});

/* ---- Issue 12, option 16: Mark IN / OUT / PERM OUT ------------------------ */

it('marks RAS boxes, skips and counts the others, and mirrors onto documents (issue 12)', function (): void {
    $ras = bb_box(['box_number' => '1']);
    $doc = bb_doc($ras);
    $nra = bb_box(['box_type' => 'NRA', 'box_number' => 'NRA1', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);

    Livewire::test(ListBoxes::class)->callTableBulkAction('markPermOut', [$ras, $nra]);

    expect($ras->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($doc->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($ras->fresh()->barcodeHistory()->where('new_status', 'PERM_OUT')->exists())->toBeTrue()
        ->and($nra->fresh()->barcode_status)->toBe('IN');

    [$title, $body] = bb_notified();
    expect($title)->toBe('1 box marked PERM OUT, 1 skipped')
        ->and($body)->toContain('Not a RAS box')->toContain('(1)')->toContain('NRA NRA1');
});

it('reports the model guards per box: PERM_OUT to OUT, PERM_OUT to IN (issue 12)', function (): void {
    $box = bb_box(['barcode_status' => 'PERM_OUT', 'box_number' => '1']);

    Livewire::test(ListBoxes::class)->callTableBulkAction('markOut', [$box]);
    expect($box->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and(bb_notified()[1])->toContain('permanent transfer')->toContain('Box 1');

    Livewire::test(ListBoxes::class)->callTableBulkAction('markIn', [$box]);
    expect($box->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and(bb_notified()[1])->toContain('NEW value')->toContain('Box 1');
});

it('Mark IN takes the location RAS through the saving hook (issue 12)', function (): void {
    $ras = bb_location('RAS');
    $box = bb_box(['barcode_status' => 'OUT']);

    Livewire::test(ListBoxes::class)->callTableBulkAction('markIn', [$box]);

    expect($box->fresh()->barcode_status)->toBe('IN')
        ->and($box->fresh()->location_id)->toBe($ras->id);
});

it('no longer offers Mark PERM_OUT on documents (issue 12)', function (): void {
    $names = fn (array $actions): array => array_map(fn (Action $a): string => (string) $a->getName(), $actions);

    expect($names(DocumentActionGroup::bulkActions()))->not->toContain('bulkMarkPermOut')
        ->and($names(DocumentActionGroup::singleHeaderActions()))->not->toContain('markPermOut');
});

/* ---- Tenancy and permissions ---------------------------------------------- */

it('does not act on a box of another repository through a forged selection (issue 9)', function (): void {
    $other = Repository::factory()->create();
    $otherBatch = Batch::withoutGlobalScopes()->create(['repository_id' => $other->id, 'batch_number' => '2', 'is_active' => true]);
    $foreign = Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => '77', 'batch_id' => $otherBatch->id, 'barcode_status' => 'IN']);
    $mine = bb_box();

    /** @var User $editor */
    $editor = bl_actor('editor', $this->repo);
    $this->actingAs($editor);

    Livewire::test(ListBoxes::class)
        ->set('selectedTableRecords', [(string) $foreign->id, (string) $mine->id])
        ->mountTableBulkAction('markOut', [$foreign, $mine])
        ->callMountedTableAction();

    expect(Box::withoutGlobalScopes()->find($foreign->id)->barcode_status)->toBe('IN')
        ->and($mine->fresh()->barcode_status)->toBe('OUT')
        ->and(bb_notified()[0] . bb_notified()[1])->not->toContain('77');
});

it('hides the new bulk actions from a viewer (issue 9)', function (string $name): void {
    $this->actingAs(bl_actor('viewer', $this->repo));

    Livewire::test(ListBoxes::class)->assertTableBulkActionHidden($name);
})->with(['changeBatch', 'sendToDisinfestation', 'finishDisinfestation', 'markIn', 'markOut', 'markPermOut', 'destroyBoxes']);

it('groups the Boxes bulk menu as Power actions, the deleting block last (issue 9)', function (): void {
    $table = Livewire::test(ListBoxes::class)->instance()->getTable();
    /** @var ActionGroup $menu */
    $menu = $table->getBulkActions()[0] ?? collect($table->getFlatBulkActions())->first()->getGroup()?->getRootGroup();

    expect($menu->getLabel())->toBe('Power actions');

    $blocks = $menu->getActions();
    /** @var ActionGroup $last */
    $last = end($blocks);
    expect(array_map(fn (BulkAction $a): ?string => $a->getName(), array_values($last->getActions())))
        ->toContain('delete', 'restore', 'forceDelete');
});
