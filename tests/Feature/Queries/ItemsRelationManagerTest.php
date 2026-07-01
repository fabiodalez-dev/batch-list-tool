<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\ItemsRelationManager;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Q5 (NAF Queries) — the itemisation relation manager renders and its "Itemise"
 * action expands a document into placeholder items.
 */
uses(RefreshDatabase::class);

function itemAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

it('renders the itemised-contents relation manager', function (): void {
    $this->actingAs(itemAdmin());
    $doc = Document::factory()->create();

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $doc,
        'pageClass' => EditDocument::class,
    ])->assertOk();
});

it('itemises a document into placeholder items via the Itemise action', function (): void {
    $this->actingAs(itemAdmin());
    $doc = Document::factory()->create();

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $doc,
        'pageClass' => EditDocument::class,
    ])
        ->callTableAction('itemise', data: ['count' => 5, 'prefix' => 'Folder'])
        ->assertHasNoTableActionErrors();

    expect($doc->items()->count())->toBe(5)
        ->and($doc->items()->orderBy('position')->first()->reference)->toBe('Folder 1');
});
