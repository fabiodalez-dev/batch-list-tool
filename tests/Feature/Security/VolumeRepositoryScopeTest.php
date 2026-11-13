<?php

declare(strict_types=1);

use App\Filament\Resources\VolumeResource;
use App\Filament\Resources\VolumeResource\Pages\CreateVolume;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;
use App\Models\Volume;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

/*
 * Security audit 2026-11-13, lead 6e: Volume has no repository of its own
 * (it belongs to a Document) and carried no scope, so the Volume resource
 * (hidden from the menu, reachable by URL) listed and showed the volumes of
 * every repository to a user restricted to one.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function sa_volumeOn(int $repositoryId, string $number): Volume
{
    return Volume::query()->create([
        'document_id' => qf_doc(['repository_id' => $repositoryId])->id,
        'volume_number' => $number,
    ]);
}

it('shows a repository-restricted user only the volumes of their repositories', function (string $role) {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $own = sa_volumeOn($a->id, 'V-A');
    $foreign = sa_volumeOn($b->id, 'V-B');
    $this->actingAs(bl_actor($role, $a)->refresh());

    livewire(ListVolumes::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(VolumeResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    $this->get(VolumeResource::getUrl('view', ['record' => $own]))->assertOk();
})->with(['editor', 'viewer']);

it('does not let an editor attach a volume to a document of another repository', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $foreignDoc = qf_doc(['repository_id' => $b->id]);
    $this->actingAs(bl_actor('editor', $a));

    livewire(CreateVolume::class)
        ->fillForm(['document_id' => $foreignDoc->id, 'volume_number' => 'V-X'])
        ->call('create')
        ->assertHasFormErrors(['document_id']);

    expect(Volume::query()->withoutGlobalScopes()->where('volume_number', 'V-X')->exists())->toBeFalse();
});

it('keeps every volume for admin', function () {
    $a = qf_repo('A');
    $b = qf_repo('B');
    $one = sa_volumeOn($a->id, 'V-A');
    $two = sa_volumeOn($b->id, 'V-B');
    $this->actingAs(bl_actor('admin', $a));

    livewire(ListVolumes::class)->assertCanSeeTableRecords([$one, $two]);
});
