<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\EditEntityType;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\ListEntityTypes;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Location;
use App\Models\Lookup\EntityType;
use App\Models\Repository;
use App\Models\Series;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The Edit page stays open after a save and after a header action, so what it
 * saves next must be what the document holds now.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create();
    $this->actingAs(bl_actor('super_admin', $this->repo));
    $this->doc = Document::factory()->create([
        'repository_id' => $this->repo->id,
        'series_id' => Series::factory()->create()->id,
        'volume_number' => '5',
        // The factory's random type is not one of the active types on the form.
        'document_type' => null,
    ]);
});

function rf_authority(string $code): Authority
{
    return Authority::query()->create(['alternative_identifier' => $code, 'surname' => 'Abela', 'given_names' => $code, 'entity_type' => 'Notary']);
}

it('keeps the past volume when the page is saved a second time', function (): void {
    Livewire::test(EditDocument::class, ['record' => $this->doc->getRouteKey()])
        ->fillForm(['volume_number' => '4'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->fillForm(['deeds' => 'second save'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->doc->pastVolumes()->pluck('previous_volume')->all())->toBe(['5'])
        ->and($this->doc->fresh()->deeds)->toBe('second save');
});

it('keeps the past authority when the page is saved a second time', function (): void {
    $old = rf_authority('R1');
    $new = rf_authority('R2');
    $this->doc->authorities()->attach($old->id);

    Livewire::test(EditDocument::class, ['record' => $this->doc->getRouteKey()])
        ->fillForm(['authorities' => [$new->id]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->doc->pastAuthorities()->pluck('previous_authority_id')->all())->toBe([$old->id]);
});

it('does not undo Replace authority when the page is saved after it', function (): void {
    $old = rf_authority('R1');
    $new = rf_authority('R2');
    $this->doc->authorities()->attach($old->id);

    Livewire::test(EditDocument::class, ['record' => $this->doc->getRouteKey()])
        ->callAction('replaceAuthority', ['old_authority_id' => $old->id, 'new_authority_id' => $new->id])
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->doc->authorities()->pluck('authorities.id')->all())->toBe([$new->id])
        ->and($this->doc->pastAuthorities()->pluck('previous_authority_id')->all())->toBe([$old->id]);
});

it('does not undo Move out of box when the page is saved after it', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS']);
    $this->doc->update(['current_box_id' => $box->id, 'batch_id' => $batch->id]);
    $location = Location::query()->create(['name' => 'Mould Room', 'type' => 'room', 'repository_id' => $this->repo->id, 'is_active' => true]);

    Livewire::test(EditDocument::class, ['record' => $this->doc->getRouteKey()])
        ->callAction('setCustodyStatus', ['custody_status' => 'not_in_box', 'to_location_id' => $location->id])
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $this->doc->fresh();
    expect($fresh->custody_status)->toBe('not_in_box')
        ->and($fresh->current_box_id)->toBeNull()
        ->and((int) $fresh->location_id)->toBe($location->id);
});

it('can still save a document whose past authority was deleted', function (): void {
    $old = rf_authority('R1');
    $this->doc->authorities()->attach($old->id);
    DocumentIdentifierHistory::recordPastAuthority($this->doc, $old->id);
    $this->doc->authorities()->detach($old->id);
    $old->delete();

    Livewire::test(EditDocument::class, ['record' => $this->doc->getRouteKey()])
        ->fillForm(['deeds' => 'after the delete'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->doc->fresh()->deeds)->toBe('after the delete')
        ->and($this->doc->pastAuthorities()->first()->previousAuthority?->is($old))->toBeTrue();
});

it('keeps a box out until the last of its documents in disinfestation is marked', function (): void {
    $batch = Batch::factory()->create(['repository_id' => $this->repo->id]);
    Location::query()->create(['name' => 'RAS', 'type' => 'external', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $disinfestation = Location::query()->create(['name' => 'Disinfestation', 'type' => 'external', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'OUT', 'location_id' => $disinfestation->id]);
    $first = Document::factory()->create(['document_type' => null, 'repository_id' => $this->repo->id, 'current_box_id' => $box->id, 'batch_id' => $batch->id, 'is_in_disinfestation' => true]);
    $second = Document::factory()->create(['repository_id' => $this->repo->id, 'current_box_id' => $box->id, 'batch_id' => $batch->id, 'is_in_disinfestation' => true]);

    Livewire::test(ListDocuments::class)->callTableBulkAction('bulkMarkDisinfested', [$first], ['disinfestation_date' => now()->toDateString()]);

    expect($box->fresh()->barcode_status)->toBe('OUT')
        ->and((int) $box->fresh()->location_id)->toBe($disinfestation->id)
        ->and($second->fresh()->is_in_disinfestation)->toBeTrue();

    Livewire::test(ListDocuments::class)->callTableBulkAction('bulkMarkDisinfested', [$second], ['disinfestation_date' => now()->toDateString()]);

    expect($box->fresh()->barcode_status)->toBe('IN')
        ->and($box->fresh()->location->name)->toBe('RAS');
});

it('does not let an entity type that authorities hold be re-coded or deleted', function (): void {
    $person = EntityType::query()->where('code', 'Person')->firstOrFail();
    Authority::query()->create(['alternative_identifier' => 'R7', 'surname' => 'Abela', 'given_names' => 'N', 'entity_type' => 'Person']);

    expect(fn () => $person->update(['code' => 'Human']))->toThrow(ValidationException::class)
        ->and(fn () => $person->fresh()->delete())->toThrow(ValidationException::class)
        ->and(EntityType::query()->where('code', 'Person')->exists())->toBeTrue();

    Livewire::test(ListEntityTypes::class)->callTableBulkAction('delete', [$person]);
    expect(EntityType::query()->where('code', 'Person')->exists())->toBeTrue();

    Livewire::test(EditEntityType::class, ['record' => $person->getRouteKey()])->callAction(DeleteAction::class);
    expect(EntityType::query()->where('code', 'Person')->exists())->toBeTrue();

    $unused = EntityType::query()->create(['code' => 'Unused', 'label' => 'Unused', 'sort_order' => 9, 'is_active' => true]);
    $unused->update(['code' => 'Spare']);
    $unused->delete();
    expect(EntityType::query()->where('code', 'Spare')->exists())->toBeFalse();
});
