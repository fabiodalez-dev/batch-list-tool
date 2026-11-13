<?php

declare(strict_types=1);

use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Models\Batch;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

/*
 * Security audit 2026-11-13, lead 6d: the repository select on the edit
 * forms only offers the user's own repositories, but the submitted value was
 * never checked against that list. An editor of repository A who edits the
 * Livewire payload moved a Batch of A into repository B. The creating hook
 * of BelongsToRepository already refused this; updating did not.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('does not let an editor move a batch into a repository they do not belong to', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $batch = qf_batch(['repository_id' => $a->id]);
    $this->actingAs(bl_actor('editor', $a));

    expect(fn () => livewire(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->fillForm(['repository_id' => $b->id])
        ->call('save'))->toThrow(DomainException::class);

    expect(Batch::withoutGlobalScopes()->find($batch->id)->repository_id)->toBe($a->id);
});

it('does not let an editor re-stamp a batch through the model either', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $batch = qf_batch(['repository_id' => $a->id]);
    $this->actingAs(bl_actor('editor', $a));

    expect(fn () => Batch::query()->findOrFail($batch->id)->forceFill(['repository_id' => $b->id])->save())
        ->toThrow(DomainException::class);
});

it('still lets an editor move a batch between two of their own repositories', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $editor = bl_actor('editor', $a);
    $editor->repositories()->syncWithoutDetaching([$b->id]);
    $batch = qf_batch(['repository_id' => $a->id]);
    $this->actingAs($editor);

    Batch::query()->findOrFail($batch->id)->forceFill(['repository_id' => $b->id])->save();

    expect(Batch::withoutGlobalScopes()->find($batch->id)->repository_id)->toBe($b->id);
});

it('still lets admin move a batch to any repository', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $batch = qf_batch(['repository_id' => $a->id]);
    $this->actingAs(bl_actor('admin', $a));

    livewire(EditBatch::class, ['record' => $batch->getRouteKey()])
        ->fillForm(['repository_id' => $b->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Batch::withoutGlobalScopes()->find($batch->id)->repository_id)->toBe($b->id);
});

it('does not let an editor publish a location as global or pull a global one into their repository', function () {
    $a = qf_repo('A');
    $own = qf_location($a->id);
    $global = qf_location(null);
    $this->actingAs(bl_actor('editor', $a));

    expect(fn () => Location::withoutGlobalScopes()->findOrFail($own->id)->forceFill(['repository_id' => null])->save())
        ->toThrow(DomainException::class);
    expect(fn () => Location::withoutGlobalScopes()->findOrFail($global->id)->forceFill(['repository_id' => $a->id])->save())
        ->toThrow(DomainException::class);

    expect(Location::withoutGlobalScopes()->find($own->id)->repository_id)->toBe($a->id)
        ->and(Location::withoutGlobalScopes()->find($global->id)->repository_id)->toBeNull();
});

it('still lets admin make a location global', function () {
    $a = qf_repo('A');
    $own = qf_location($a->id);
    $this->actingAs(bl_actor('admin', $a));

    Location::withoutGlobalScopes()->findOrFail($own->id)->forceFill(['repository_id' => null])->save();

    expect(Location::withoutGlobalScopes()->find($own->id)->repository_id)->toBeNull();
});
