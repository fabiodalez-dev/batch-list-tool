<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\RelationManagers\HistoryRelationManager as BoxHistoryTab;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\HistoryRelationManager as DocumentHistoryTab;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\History\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\AuditableObserver;
use Spatie\Permission\Models\Role;

/**
 * One chronology per box and per document (RFQ §3.1.5–§3.1.8).
 *
 * Audits are switched on for the console here: config/audit.php leaves
 * console auditing off, so without this the audit half of the timeline would
 * be empty in tests and they would pass without proving anything.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['audit.console' => true]);
    // The auditing package attaches its observer once, when a model boots,
    // and only if auditing is on at that moment. Document boots with the app
    // (AppServiceProvider attaches its own observer), before the line above,
    // so it would never be audited here. Re-attach it the way production's
    // queued imports do (LogsImportRows).
    Document::observe(AuditableObserver::class);
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'HTL']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id, 'name' => 'Charlene Test']);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);

    $this->batch27 = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '27', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->batch29 = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '29', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->shelf = Location::factory()->create(['code' => 'HTL-1', 'name' => 'Archive 1', 'repository_id' => $this->repo->id]);
});

function htl_box(array $attrs = []): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge([
        'box_type' => 'RAS', 'box_number' => '223', 'batch_id' => test()->batch27->id,
        'barcode' => 'AA34092', 'barcode_status' => 'IN', 'is_legacy' => false,
    ], $attrs));
}

it('puts every kind of box event in one list, ids turned into names', function (): void {
    $box = htl_box();
    $box->update(['batch_id' => $this->batch29->id, 'tracking_note' => 'At NRA as from 6/3/2024']);
    $box->update(['seal_number' => '15329213']);
    $box->update(['location_id' => $this->shelf->id]);
    $box->update(['barcode' => 'AA34307']);

    $entries = Timeline::forBox($box->fresh());
    $kinds = $entries->pluck('kind')->unique()->values()->all();

    expect($kinds)->toContain(Timeline::KIND_CREATED, Timeline::KIND_CHANGE, Timeline::KIND_SEAL, Timeline::KIND_LOCATION, Timeline::KIND_BARCODE);

    // The audit said batch_id 27-id → 29-id; the timeline says Batch 27 → 29.
    $batch = $entries->firstWhere('subject', 'Batch');
    expect($batch['from'])->toBe('27')->and($batch['to'])->toBe('29')->and($batch['by'])->toBe('Charlene Test');

    // A change with a dedicated log is listed once, from that log.
    expect($entries->where('subject', 'Location')->count())->toBe(1)
        ->and($entries->firstWhere('subject', 'Location')['to'])->toContain('Archive 1');

    // Newest first.
    $dated = $entries->filter(fn ($e) => $e['at'] !== null)->pluck('at')->values();
    expect($dated->first()->greaterThanOrEqualTo($dated->last()))->toBeTrue();
});

it('lists documents moving in and out of a box, legacy undated moves last', function (): void {
    $from = htl_box();
    $to = htl_box(['box_number' => '10', 'barcode' => 'AA34307', 'batch_id' => $this->batch29->id]);
    $series = Series::create(['code' => 'HTLS', 'title' => 'S', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'R60/007', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'T']);

    BoxMovement::create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'from_box_id' => null, 'to_box_id' => $from->id, 'movement_date' => null, 'sequence' => 1]);
    BoxMovement::create(['document_id' => $doc->id, 'repository_id' => $this->repo->id, 'from_box_id' => $from->id, 'to_box_id' => $to->id, 'movement_date' => now()->subDay(), 'user_id' => $this->user->id]);

    $entries = Timeline::forBox($from);
    $moves = $entries->where('kind', Timeline::KIND_MOVE)->values();

    expect($moves)->toHaveCount(2)
        ->and($moves[0]['subject'])->toBe('Document R60/007 left')
        ->and($moves[0]['to'])->toBe('RAS 10 · AA34307')
        ->and($moves[1]['subject'])->toBe('Document R60/007 came in')
        ->and($moves[1]['at'])->toBeNull()
        ->and($entries->last()['key'])->toBe($moves[1]['key']);
});

it('shows the box History tab, filters it by event, searches it and exports it', function (): void {
    $box = htl_box();
    $box->update(['seal_number' => 'S-1']);
    $box->update(['tracking_note' => 'Moved for disinfestation']);

    $tab = Livewire::test(BoxHistoryTab::class, ['ownerRecord' => $box, 'pageClass' => EditBox::class])
        ->assertOk()
        ->assertSee('Seal number')
        ->assertSee('Moved for disinfestation')
        ->assertSee('Charlene Test');

    $tab->filterTable('kind', [Timeline::KIND_SEAL])
        ->assertSee('S-1')
        ->assertDontSee('Moved for disinfestation');

    Livewire::test(BoxHistoryTab::class, ['ownerRecord' => $box, 'pageClass' => EditBox::class])
        ->searchTable('disinfestation')
        ->assertSee('Moved for disinfestation')
        ->assertDontSee('S-1');

    $export = Livewire::test(BoxHistoryTab::class, ['ownerRecord' => $box, 'pageClass' => EditBox::class])
        ->callTableAction('export_history');
    $csv = (string) base64_decode((string) $export->effects['download']['content'], true);

    expect($csv)->toContain('When,Event,What,From,To,By,Source')
        ->toContain('S-1')
        ->toContain('Moved for disinfestation');
});

it('shows a document History tab with flags and identifier changes', function (): void {
    $series = Series::create(['code' => 'HTLD', 'title' => 'S', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'R61/001', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'T']);
    $doc->update(['notes' => 'Water damage on f. 12']);
    $doc->identifierHistory()->create(['previous_identifier' => 'R61/OLD', 'new_identifier' => 'R61/001', 'changed_at' => now(), 'changed_by_user_id' => $this->user->id, 'repository_id' => $this->repo->id]);
    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id, 'flagged_by_user_id' => $this->user->id, 'title' => 'Check the foliation', 'status' => 'open']);

    Livewire::test(DocumentHistoryTab::class, ['ownerRecord' => $doc, 'pageClass' => EditDocument::class])
        ->assertOk()
        ->assertSee('Water damage on f. 12')
        ->assertSee('R61/OLD')
        ->assertSee('Check the foliation');
});

it('is hidden from users who may not read the audit trail', function (): void {
    Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewer = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $viewer->assignRole('viewer');

    expect(BoxHistoryTab::canViewForRecord(htl_box(), EditBox::class))->toBeTrue();

    $this->actingAs($viewer);
    expect(BoxHistoryTab::canViewForRecord(htl_box(['box_number' => '224', 'barcode' => 'AA00224']), EditBox::class))
        ->toBe($viewer->can('view_any_audit'));
});
