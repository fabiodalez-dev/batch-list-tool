<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\BoxResource\RelationManagers\LocationHistoryRelationManager as BoxLocationTab;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\LocationHistoryRelationManager as DocumentLocationTab;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxLocationHistory;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.6 — a box's moves between locations are kept, not overwritten.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'LHT']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id, 'name' => 'Charlene Test']);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);

    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9301', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->shelfA = Location::factory()->create(['code' => 'LHT-A', 'name' => 'Shelf A', 'repository_id' => $this->repo->id]);
    $this->shelfB = Location::factory()->create(['code' => 'LHT-B', 'name' => 'Shelf B', 'repository_id' => $this->repo->id]);
});

function lht_box(array $attrs = []): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge([
        'box_type' => 'RAS', 'box_number' => 'L' . random_int(100, 999), 'batch_id' => test()->batch->id,
        'barcode' => 'LH' . random_int(100000, 999999), 'barcode_status' => 'IN', 'is_legacy' => false,
    ], $attrs));
}

it('records where a box starts, each move after, and nothing when it does not move', function (): void {
    $box = lht_box(['location_id' => $this->shelfA->id]);

    $box->update(['location_id' => $this->shelfB->id]);
    $box->update(['notes' => 'an unrelated edit']);
    $box->update(['location_id' => null]);

    $trail = BoxLocationHistory::query()->where('box_id', $box->id)->orderBy('id')->get();

    expect($trail)->toHaveCount(3)
        ->and($trail->map(fn ($r) => [$r->from_location_id, $r->to_location_id, $r->source])->all())->toBe([
            [null, $this->shelfA->id, 'create'],
            [$this->shelfA->id, $this->shelfB->id, 'update'],
            [$this->shelfB->id, null, 'update'],
        ])
        ->and($trail[1]->changed_by_user_id)->toBe($this->user->id)
        ->and($trail[1]->repository_id)->toBe($this->repo->id);
});

it('keeps the place readable after the location is renamed', function (): void {
    $box = lht_box();
    $box->update(['location_id' => $this->shelfA->id]);
    $label = BoxLocationHistory::query()->where('box_id', $box->id)->value('to_location_label');

    $this->shelfA->update(['name' => 'Shelf A (renamed)']);

    expect($label)->toContain('Shelf A')
        ->and(BoxLocationHistory::query()->where('box_id', $box->id)->value('to_location_label'))->toBe($label);
});

it('records a move made with the bulk Relocate action on the box list', function (): void {
    $boxes = collect([lht_box(['location_id' => $this->shelfA->id]), lht_box(['location_id' => $this->shelfA->id])]);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', $boxes->pluck('id')->all(), data: ['location_id' => $this->shelfB->id]);

    foreach ($boxes as $box) {
        expect(BoxLocationHistory::query()->where('box_id', $box->id)->where('to_location_id', $this->shelfB->id)->exists())
            ->toBeTrue("box {$box->box_number}: the bulk relocate left no trace");
    }
});

it('shows the trail on the box Location history tab', function (): void {
    $box = lht_box(['location_id' => $this->shelfA->id]);
    $box->update(['location_id' => $this->shelfB->id]);

    Livewire::test(BoxLocationTab::class, ['ownerRecord' => $box, 'pageClass' => EditBox::class])
        ->assertOk()
        ->assertCanSeeTableRecords(BoxLocationHistory::query()->where('box_id', $box->id)->get())
        ->assertSee('Shelf B')
        ->assertSee('Charlene Test');
});

it('shows the document trail that was recorded but never displayed', function (): void {
    $series = Series::create(['code' => 'LHTS', 'title' => 'S', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $document = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'LHT-1', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'T', 'location_id' => $this->shelfA->id]);
    $document->update(['location_id' => $this->shelfB->id]);

    Livewire::test(DocumentLocationTab::class, ['ownerRecord' => $document, 'pageClass' => EditDocument::class])
        ->assertOk()
        ->assertCanSeeTableRecords($document->locationHistory()->get())
        ->assertSee('Shelf A')
        ->assertSee('Shelf B');
});
