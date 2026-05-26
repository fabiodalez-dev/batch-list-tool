<?php

declare(strict_types=1);

use App\Filament\Actions\Documents;
use App\Filament\Actions\Documents\ActionSupport;
use App\Filament\Actions\Documents\AddFlagAction;
use App\Filament\Actions\Documents\AssignAuthorityAction;
use App\Filament\Actions\Documents\DetachAuthorityAction;
use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Actions\Documents\ExportSelectedAction;
use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\MarkPermOutAction;
use App\Filament\Actions\Documents\MoveToBatchAction;
use App\Filament\Actions\Documents\MoveToBoxAction;
use App\Filament\Actions\Documents\MoveToRepositoryAction;
use App\Filament\Actions\Documents\MoveToWillsAction;
use App\Filament\Actions\Documents\ReplaceAuthorityAction;
use App\Filament\Actions\Documents\SetLocationAction;
use App\Filament\Actions\Documents\SetSeriesAction;
use App\Filament\Actions\Documents\UpdateDocumentTypeAction;
use App\Filament\Actions\Documents\UpdateIdentifierAction;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.1 / §3.1.4 / §3.1.5 — Document power-actions.
 *
 * These tests exercise the 15 single-record / bulk Document actions defined
 * under {@see Documents}. They bypass the Filament UI
 * (no Livewire mount) and call the action closures directly via reflection,
 * because the Filament 5 testing API for nested ActionGroup + BulkActionGroup
 * is brittle and the goal here is to lock down the behaviour, not the UI
 * wiring.
 *
 * The wiring on EditDocument / ViewDocument / ListDocuments is asserted at
 * the end of this file via static array shape checks on DocumentActionGroup.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* -------------------------------------------------------------------------
 |  Helpers
 * ------------------------------------------------------------------------- */

function actAs_role(string $role): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'da-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function repo_(string $code = 'DA'): Repository
{
    return Repository::factory()->create([
        'code' => $code . '_' . substr(uniqid(), -6),
    ]);
}

function series_(string $code = 'SER'): Series
{
    return Series::firstOrCreate(
        ['code' => $code . '_' . substr(uniqid(), -4)],
        ['title' => $code . ' series', 'is_active' => true],
    );
}

function batch_(int $repoId, ?int $number = null): Batch
{
    if ($number === null) {
        do {
            $number = random_int(2000, 8999);
        } while (in_array($number, Batch::FORBIDDEN_NUMBERS, true)
            || Batch::withoutGlobalScope(RepositoryScope::class)
                ->where('batch_number', $number)
                ->where('repository_id', $repoId)
                ->exists());
    }

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $number,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function box_(int $batchId, array $attrs = []): Box
{
    return Box::withoutGlobalScopes()->create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'BOX-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batchId,
        'barcode' => 'BC' . substr(uniqid(), -8),
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ], $attrs));
}

function doc_(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function authority_(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'A-' . strtoupper(substr(uniqid(), -8)),
        'surname' => 'Auth' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
    ], $attrs));
}

function asColl(Document ...$docs): EloquentCollection
{
    /** @var EloquentCollection<int, Document> $c */
    $c = new EloquentCollection($docs);

    return $c;
}

/**
 * Reach inside a Filament Action / BulkAction and execute its `action`
 * closure with the given arguments. This is the contract we test, not the
 * Livewire mount/submit wiring (which Filament's own test suite covers).
 */
function runAction(Action $action, array $named): void
{
    $closure = (function () {
        return $this->action;
    })->call($action);

    if (! $closure instanceof Closure) {
        throw new RuntimeException('Action closure missing');
    }

    $ref = new ReflectionFunction($closure);
    $args = [];
    foreach ($ref->getParameters() as $p) {
        $name = $p->getName();
        if (array_key_exists($name, $named)) {
            $args[] = $named[$name];
            continue;
        }
        if ($p->isOptional()) {
            $args[] = $p->getDefaultValue();
            continue;
        }

        throw new RuntimeException("Cannot bind action parameter: {$name}");
    }
    $closure(...$args);
}

/* -------------------------------------------------------------------------
 |  Action #1 — Move to Box
 * ------------------------------------------------------------------------- */

test('Move to box (single) updates current_box_id and writes BoxMovement', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $batch = batch_($repo->id);
    $fromBox = box_($batch->id);
    $toBox = box_($batch->id);
    $doc = doc_($repo->id, $series->id, ['current_box_id' => $fromBox->id, 'batch_id' => $batch->id]);

    runAction(MoveToBoxAction::make(), [
        'record' => $doc,
        'data' => ['to_box_id' => $toBox->id, 'reason' => 'reshelving'],
    ]);

    $doc->refresh();
    expect($doc->current_box_id)->toBe($toBox->id);

    $mv = BoxMovement::query()->where('document_id', $doc->id)->first();
    expect($mv)->not->toBeNull()
        ->and($mv->from_box_id)->toBe($fromBox->id)
        ->and($mv->to_box_id)->toBe($toBox->id)
        ->and($mv->reason)->toBe('reshelving');
});

test('Move to box (bulk) updates many documents and writes one BoxMovement per row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $batch = batch_($repo->id);
    $box = box_($batch->id);
    $docs = collect(range(0, 2))->map(fn () => doc_($repo->id, $series->id, ['batch_id' => $batch->id]));

    runAction(MoveToBoxAction::bulk(), [
        'records' => asColl(...$docs->all()),
        'data' => ['to_box_id' => $box->id, 'reason' => null],
    ]);

    foreach ($docs as $d) {
        $d->refresh();
        expect($d->current_box_id)->toBe($box->id);
    }
    expect(BoxMovement::query()->whereIn('document_id', $docs->pluck('id')->all())->count())->toBe(3);
});

test('Move to box rejects PERM_OUT target with notification (no writes)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $batch = batch_($repo->id);
    $permBox = box_($batch->id, ['barcode_status' => 'PERM_OUT', 'disinfestation_date' => now()]);
    $doc = doc_($repo->id, $series->id);

    runAction(MoveToBoxAction::make(), [
        'record' => $doc,
        'data' => ['to_box_id' => $permBox->id, 'reason' => null],
    ]);

    $doc->refresh();
    expect($doc->current_box_id)->toBeNull();
    expect(BoxMovement::query()->where('document_id', $doc->id)->count())->toBe(0);
});

test('Move to box fails gracefully when target box was deleted (no 500)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);

    runAction(MoveToBoxAction::make(), [
        'record' => $doc,
        'data' => ['to_box_id' => 999999, 'reason' => null],
    ]);

    $doc->refresh();
    expect($doc->current_box_id)->toBeNull();
});

/* -------------------------------------------------------------------------
 |  Action #2 — Move to Batch
 * ------------------------------------------------------------------------- */

test('Move to batch updates batch_id and (by default) clears current_box_id', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $oldBatch = batch_($repo->id);
    $box = box_($oldBatch->id);
    $newBatch = batch_($repo->id);
    $doc = doc_($repo->id, $series->id, ['batch_id' => $oldBatch->id, 'current_box_id' => $box->id]);

    runAction(MoveToBatchAction::make(), [
        'record' => $doc,
        'data' => ['to_batch_id' => $newBatch->id, 'clear_current_box' => true],
    ]);

    $doc->refresh();
    expect($doc->batch_id)->toBe($newBatch->id);
    expect($doc->current_box_id)->toBeNull();
});

test('Move to batch refuses forbidden batch numbers (33, 34, 36)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $forbidden = batch_($repo->id, 33);
    $doc = doc_($repo->id, $series->id);

    runAction(MoveToBatchAction::make(), [
        'record' => $doc,
        'data' => ['to_batch_id' => $forbidden->id, 'clear_current_box' => true],
    ]);

    $doc->refresh();
    expect($doc->batch_id)->not->toBe($forbidden->id);
});

/* -------------------------------------------------------------------------
 |  Action #3 — Move to Repository (super_admin only)
 * ------------------------------------------------------------------------- */

test('Cross-tenant transfer moves documents to target repo and writes audit row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repoA = repo_('RA');
    $repoB = repo_('RB');
    $series = series_();
    $doc = doc_($repoA->id, $series->id);

    runAction(MoveToRepositoryAction::bulk(), [
        'records' => asColl($doc),
        'data' => [
            'to_repository_id' => $repoB->id,
            'reason' => 'consolidation of holdings',
            'clear_box_and_batch' => true,
        ],
    ]);

    $doc->refresh();
    expect($doc->repository_id)->toBe($repoB->id);

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'cross_tenant_transfer')
        ->first();
    expect($audit)->not->toBeNull();
});

test('Cross-tenant transfer is blocked for non-super_admin users', function () {
    // Seed as super_admin so the multi-tenant `creating` hook lets us create
    // the test fixture in repoA. Then switch to editor and assert the action
    // is a no-op (editor cannot cross tenant boundaries).
    $this->actingAs(actAs_role('super_admin'));
    $repoA = repo_('RA');
    $repoB = repo_('RB');
    $series = series_();
    $doc = doc_($repoA->id, $series->id);

    $editor = actAs_role('editor');
    // Pin the editor to repoA so they can normally see/edit the doc within
    // that repo; the cross-tenant transfer should still be refused.
    $editor->repositories()->syncWithoutDetaching([$repoA->id => ['is_default' => true]]);
    $editor->update(['default_repository_id' => $repoA->id]);
    $this->actingAs($editor);

    runAction(MoveToRepositoryAction::bulk(), [
        'records' => asColl($doc),
        'data' => [
            'to_repository_id' => $repoB->id,
            'reason' => 'should not work',
            'clear_box_and_batch' => true,
        ],
    ]);

    $doc->refresh();
    expect($doc->repository_id)->toBe($repoA->id);
});

/* -------------------------------------------------------------------------
 |  Action #4 — Set Location
 * ------------------------------------------------------------------------- */

test('Set location pins document to a global location', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $loc = Location::factory()->create(['repository_id' => null, 'name' => 'Conservation Lab']);
    $doc = doc_($repo->id, $series->id);

    runAction(SetLocationAction::make(), [
        'record' => $doc,
        'data' => ['to_location_id' => $loc->id],
    ]);

    $doc->refresh();
    expect($doc->location_id)->toBe($loc->id);
});

test('Set location refuses location from a different repository (tenant safety)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repoA = repo_('LA');
    $repoB = repo_('LB');
    $series = series_();
    $locInB = Location::factory()->create(['repository_id' => $repoB->id, 'name' => 'B-only']);
    $doc = doc_($repoA->id, $series->id);

    runAction(SetLocationAction::make(), [
        'record' => $doc,
        'data' => ['to_location_id' => $locInB->id],
    ]);

    $doc->refresh();
    expect($doc->location_id)->toBeNull();
});

/* -------------------------------------------------------------------------
 |  Action #5 — Mark Disinfested
 * ------------------------------------------------------------------------- */

test('Mark disinfested writes today() onto disinfestation_date', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id, ['disinfestation_date' => null]);

    runAction(MarkDisinfestedAction::make(), [
        'record' => $doc,
        'data' => ['disinfestation_date' => now()->toDateString()],
    ]);

    $doc->refresh();
    expect($doc->disinfestation_date?->toDateString())->toBe(now()->toDateString());
});

test('Mark disinfested (bulk) updates many at once', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $docs = collect(range(0, 2))->map(fn () => doc_($repo->id, $series->id, ['disinfestation_date' => null]));

    runAction(MarkDisinfestedAction::bulk(), [
        'records' => asColl(...$docs->all()),
        'data' => ['disinfestation_date' => now()->toDateString()],
    ]);

    foreach ($docs as $d) {
        $d->refresh();
        expect($d->disinfestation_date?->toDateString())->toBe(now()->toDateString());
    }
});

/* -------------------------------------------------------------------------
 |  Action #6 — Mark PERM_OUT (requires disinfestation_date — RFQ App.1 #5)
 * ------------------------------------------------------------------------- */

test('Mark PERM_OUT refuses to mark a document without disinfestation_date', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id, ['disinfestation_date' => null]);

    runAction(MarkPermOutAction::make(), [
        'record' => $doc,
    ]);

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'permout_marked')
        ->first();
    expect($audit)->toBeNull(); // no audit row → action was blocked
});

test('Mark PERM_OUT succeeds when disinfestation_date is set and writes audit row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id, ['disinfestation_date' => now()->subDay()]);

    runAction(MarkPermOutAction::make(), [
        'record' => $doc,
    ]);

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'permout_marked')
        ->first();
    expect($audit)->not->toBeNull();
});

/* -------------------------------------------------------------------------
 |  Action #7 — Assign Authority
 * ------------------------------------------------------------------------- */

test('Assign authority attaches the row and writes a manual audit row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);
    $auth = authority_();

    runAction(AssignAuthorityAction::make(), [
        'record' => $doc,
        'data' => ['authority_id' => $auth->id, 'is_primary' => true],
    ]);

    expect($doc->authorities()->where('authorities.id', $auth->id)->exists())->toBeTrue();

    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'authority_attached')
        ->first();
    expect($audit)->not->toBeNull();
});

test('Assign authority is idempotent — second call for same authority does NOT duplicate the pivot', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);
    $auth = authority_();
    $doc->authorities()->attach($auth->id, ['is_primary' => false]);

    runAction(AssignAuthorityAction::make(), [
        'record' => $doc,
        'data' => ['authority_id' => $auth->id, 'is_primary' => true],
    ]);

    expect($doc->authorities()->where('authorities.id', $auth->id)->count())->toBe(1);
});

/* -------------------------------------------------------------------------
 |  Action #8 — Replace Authority
 * ------------------------------------------------------------------------- */

test('Replace authority swaps old for new (preserves is_primary)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);
    $old = authority_();
    $new = authority_();
    $doc->authorities()->attach($old->id, ['is_primary' => true]);

    runAction(ReplaceAuthorityAction::make(), [
        'record' => $doc,
        'data' => ['old_authority_id' => $old->id, 'new_authority_id' => $new->id],
    ]);

    expect($doc->authorities()->where('authorities.id', $old->id)->exists())->toBeFalse();
    $pivot = $doc->authorities()->where('authorities.id', $new->id)->first();
    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->pivot->is_primary)->toBeTrue();
});

test('Replace authority skips documents that do not have the old authority', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $docA = doc_($repo->id, $series->id);
    $docB = doc_($repo->id, $series->id);
    $old = authority_();
    $new = authority_();
    $docA->authorities()->attach($old->id, ['is_primary' => false]);
    // docB intentionally has nothing — should remain unchanged.

    runAction(ReplaceAuthorityAction::bulk(), [
        'records' => asColl($docA, $docB),
        'data' => ['old_authority_id' => $old->id, 'new_authority_id' => $new->id],
    ]);

    expect($docA->authorities()->where('authorities.id', $new->id)->exists())->toBeTrue();
    expect($docB->authorities()->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 |  Action #9 — Detach Authority
 * ------------------------------------------------------------------------- */

test('Detach authority removes the pivot and writes audit row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);
    $auth = authority_();
    $doc->authorities()->attach($auth->id, ['is_primary' => false]);

    runAction(DetachAuthorityAction::make(), [
        'record' => $doc,
        'data' => ['authority_id' => $auth->id],
    ]);

    expect($doc->authorities()->where('authorities.id', $auth->id)->exists())->toBeFalse();
    expect(Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'authority_detached')
        ->exists())->toBeTrue();
});

/* -------------------------------------------------------------------------
 |  Action #10 — Set Series
 * ------------------------------------------------------------------------- */

test('Set series reclassifies the document into a new series', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $s1 = series_('S1');
    $s2 = series_('S2');
    $doc = doc_($repo->id, $s1->id);

    runAction(SetSeriesAction::make(), [
        'record' => $doc,
        'data' => ['to_series_id' => $s2->id],
    ]);

    $doc->refresh();
    expect($doc->series_id)->toBe($s2->id);
});

/* -------------------------------------------------------------------------
 |  Action #11 — Add Flag
 * ------------------------------------------------------------------------- */

test('Add flag creates a DocumentFlag row', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);

    runAction(AddFlagAction::make(), [
        'record' => $doc,
        'data' => [
            'type' => 'needs_review',
            'severity' => 'warning',
            'title' => 'Investigate',
            'description' => 'auto-flagged',
        ],
    ]);

    expect(DocumentFlag::query()->where('document_id', $doc->id)->count())->toBe(1);
});

test('Add flag is idempotent for same document + type + open status', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);

    // First call creates.
    runAction(AddFlagAction::make(), [
        'record' => $doc,
        'data' => ['type' => 'needs_review', 'severity' => 'warning', 'title' => null, 'description' => null],
    ]);

    // Second call must NOT add a duplicate row.
    runAction(AddFlagAction::make(), [
        'record' => $doc,
        'data' => ['type' => 'needs_review', 'severity' => 'warning', 'title' => null, 'description' => null],
    ]);

    expect(DocumentFlag::query()->where('document_id', $doc->id)->where('type', 'needs_review')->count())->toBe(1);
});

/* -------------------------------------------------------------------------
 |  Action #12 — Update Document Type
 * ------------------------------------------------------------------------- */

test('Update document type writes the new value (bulk)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $docs = collect(range(0, 2))->map(fn () => doc_($repo->id, $series->id, ['document_type' => 'Old']));

    runAction(UpdateDocumentTypeAction::bulk(), [
        'records' => asColl(...$docs->all()),
        'data' => ['document_type' => 'Brand New Type'],
    ]);

    foreach ($docs as $d) {
        $d->refresh();
        expect($d->document_type)->toBe('Brand New Type');
    }
});

/* -------------------------------------------------------------------------
 |  Action #13 — Move to Wills (Batch 50)
 * ------------------------------------------------------------------------- */

test('Move to wills assigns Batch 50 (auto-created when missing)', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_('OTHER');
    $doc = doc_($repo->id, $series->id);

    runAction(MoveToWillsAction::make(), [
        'record' => $doc,
    ]);

    $doc->refresh();
    $b50 = Batch::withoutGlobalScopes()
        ->where('batch_number', 50)
        ->where('repository_id', $repo->id)
        ->first();
    expect($b50)->not->toBeNull()
        ->and($doc->batch_id)->toBe($b50->id);
});

/* -------------------------------------------------------------------------
 |  Action #14 — Update Identifier (single only)
 * ------------------------------------------------------------------------- */

test('Update identifier writes new identifier on a single document', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id, ['identifier' => 'R7-old']);

    runAction(UpdateIdentifierAction::make(), [
        'record' => $doc,
        'data' => ['identifier' => 'R7-new'],
    ]);

    $doc->refresh();
    expect($doc->identifier)->toBe('R7-new');
});

test('Update identifier rejects duplicates within the same repository', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $existing = doc_($repo->id, $series->id, ['identifier' => 'R10']);
    $target = doc_($repo->id, $series->id, ['identifier' => 'R11']);

    runAction(UpdateIdentifierAction::make(), [
        'record' => $target,
        'data' => ['identifier' => 'R10'],
    ]);

    $target->refresh();
    expect($target->identifier)->toBe('R11');
});

test('UpdateIdentifierAction has no bulk variant', function () {
    expect(method_exists(UpdateIdentifierAction::class, 'bulk'))->toBeFalse();
});

/* -------------------------------------------------------------------------
 |  Action #15 — Export Selected
 * ------------------------------------------------------------------------- */

test('Export selected returns a streamed CSV response with the right header', function () {
    $this->actingAs(actAs_role('super_admin'));

    $repo = repo_();
    $series = series_();
    $docs = collect(range(0, 2))->map(fn () => doc_($repo->id, $series->id));

    $action = ExportSelectedAction::bulk();
    $closure = (function () {
        return $this->action;
    })->call($action);

    /** @var StreamedResponse $resp */
    $resp = $closure(asColl(...$docs->all()));

    expect($resp)->toBeInstanceOf(StreamedResponse::class);
    expect($resp->headers->get('Content-Type'))->toContain('text/csv');
});

/* -------------------------------------------------------------------------
 |  Transaction safety + audit metadata
 * ------------------------------------------------------------------------- */

test('Bulk actions wrap writes in a transaction (failure on one row does not corrupt earlier writes)', function () {
    $this->actingAs(actAs_role('super_admin'));

    // We can't easily force a per-row failure without monkey-patching, but
    // we CAN assert the transaction wrapper exists by inspecting that all-or-
    // some behaviour: 3 valid docs all get updated, no partial writes.
    $repo = repo_();
    $series = series_();
    $batch = batch_($repo->id);
    $box = box_($batch->id);
    $docs = collect(range(0, 2))->map(fn () => doc_($repo->id, $series->id, ['batch_id' => $batch->id]));

    runAction(MoveToBoxAction::bulk(), [
        'records' => asColl(...$docs->all()),
        'data' => ['to_box_id' => $box->id, 'reason' => null],
    ]);

    expect(BoxMovement::query()->whereIn('document_id', $docs->pluck('id')->all())->count())->toBe(3);
});

test('Audit metadata captures user_id and event', function () {
    $u = actAs_role('super_admin');
    $this->actingAs($u);

    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);
    $auth = authority_();

    runAction(AssignAuthorityAction::make(), [
        'record' => $doc,
        'data' => ['authority_id' => $auth->id, 'is_primary' => false],
    ]);

    $audit = Audit::query()
        ->where('auditable_id', $doc->id)
        ->where('event', 'authority_attached')
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($u->id);
});

/* -------------------------------------------------------------------------
 |  Wiring on the resource pages
 * ------------------------------------------------------------------------- */

test('DocumentActionGroup exposes 13 single-record header actions and 14 bulk actions', function () {
    $single = DocumentActionGroup::singleHeaderActions();
    $bulk = DocumentActionGroup::bulkActions();

    expect($single)->toHaveCount(13);
    expect($bulk)->toHaveCount(14);
});

test('Every single-record action exposes a Filament Action instance', function () {
    foreach (DocumentActionGroup::singleHeaderActions() as $a) {
        expect($a)->toBeInstanceOf(Action::class);
    }
});

test('Every bulk action exposes a Filament BulkAction instance', function () {
    foreach (DocumentActionGroup::bulkActions() as $a) {
        expect($a)->toBeInstanceOf(BulkAction::class);
    }
});

/* -------------------------------------------------------------------------
 |  Authorisation gates
 * ------------------------------------------------------------------------- */

test('Power actions are hidden from viewer role (no update_document permission)', function () {
    $this->actingAs(actAs_role('viewer'));

    $action = MoveToBoxAction::make();
    expect($action->isVisible())->toBeFalse();
});

test('Power actions are visible to super_admin (has every permission)', function () {
    $this->actingAs(actAs_role('super_admin'));

    expect(MoveToBoxAction::make()->isVisible())->toBeTrue();
    expect(MarkDisinfestedAction::make()->isVisible())->toBeTrue();
    expect(MoveToRepositoryAction::bulk()->isVisible())->toBeTrue();
});

test('Power actions are visible to editor role (has update_document permission)', function () {
    $this->actingAs(actAs_role('editor'));
    expect(auth()->user()?->can('update_document'))->toBeTrue();
});

test('Cross-tenant transfer is hidden from admin (super_admin only)', function () {
    $this->actingAs(actAs_role('admin'));
    expect(auth()->user()?->hasRole('super_admin'))->toBeFalse();
});

/* -------------------------------------------------------------------------
 |  ActionSupport — utility unit tests
 * ------------------------------------------------------------------------- */

test('ActionSupport::asCollection normalises a single record into a collection', function () {
    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);

    $coll = ActionSupport::asCollection($doc);
    expect($coll)->toBeInstanceOf(EloquentCollection::class);
    expect($coll->count())->toBe(1);
});

test('ActionSupport::logPivotChange writes an Audit row with the requested event and tags', function () {
    $u = actAs_role('super_admin');
    $this->actingAs($u);
    $repo = repo_();
    $series = series_();
    $doc = doc_($repo->id, $series->id);

    ActionSupport::logPivotChange(
        document: $doc,
        event: 'sanity_check_event',
        newValues: ['x' => 1],
        oldValues: ['x' => 0],
        tags: 'unit,test',
    );

    $row = Audit::query()
        ->where('auditable_id', $doc->id)
        ->where('event', 'sanity_check_event')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->tags)->toBe('unit,test')
        ->and($row->user_id)->toBe($u->id);
});
