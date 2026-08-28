<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\DocumentTypeResource\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function dt_superAdmin(): User
{
    $u = User::factory()->create([
        'email' => 'dt-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/* ── nextIdentifier() ─────────────────────────────────────────────────── */

it('suggests DT00001 on an empty table', function () {
    expect(DocumentTypeResource::nextIdentifier())->toBe('DT00001');
});

it('suggests DT00006 when the max identifier is DT00005', function () {
    DocumentType::create(['name' => 'Alpha', 'identifier' => 'DT00003']);
    DocumentType::create(['name' => 'Beta', 'identifier' => 'DT00005']);
    // Non-matching identifiers must be ignored by the max scan.
    DocumentType::create(['name' => 'Legacy', 'identifier' => 'REG']);
    DocumentType::create(['name' => 'NullId', 'identifier' => null]);

    expect(DocumentTypeResource::nextIdentifier())->toBe('DT00006');
});

/* ── Form: identifier first + required, name required ─────────────────── */

it('places a required Identifier before a required Name in the form', function () {
    $this->actingAs(dt_superAdmin());

    $lw = Livewire::test(ListDocumentTypes::class);
    $schema = Schema::make($lw->instance());

    $components = DocumentTypeResource::form($schema)->getComponents();
    $names = collect($components)
        ->filter(fn ($c) => method_exists($c, 'getName'))
        ->map(fn ($c) => $c->getName())
        ->values();

    $identifierIndex = $names->search('identifier');
    $nameIndex = $names->search('name');

    expect($identifierIndex)->not->toBeFalse()
        ->and($nameIndex)->not->toBeFalse()
        ->and($identifierIndex)->toBeLessThan($nameIndex);

    $identifier = collect($components)->first(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'identifier');
    $nameField = collect($components)->first(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'name');

    expect($identifier->isRequired())->toBeTrue()
        ->and($nameField->isRequired())->toBeTrue();
});

it('pre-fills the identifier on the create form with the next consecutive code', function () {
    DocumentType::create(['name' => 'Seed', 'identifier' => 'DT00009']);

    $this->actingAs(dt_superAdmin());

    Livewire::test(CreateDocumentType::class)
        ->assertOk()
        ->assertFormSet(['identifier' => 'DT00010']);
});

/* ── Table: DeleteBulkAction present ──────────────────────────────────── */

it('exposes a bulk delete action on the table', function () {
    $this->actingAs(dt_superAdmin());

    Livewire::test(ListDocumentTypes::class)
        ->assertOk()
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionExists('deactivate');
});
