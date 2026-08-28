<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\DocumentTypeResource\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
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

/* ── nextIdentifier() edge cases ──────────────────────────────────────── */

it('EDGE: nextIdentifier ignores non-DT identifiers', function () {
    DocumentType::create(['name' => 'Legacy', 'identifier' => 'REG']);
    DocumentType::create(['name' => 'Other', 'identifier' => 'DT12A']); // malformed
    expect(DocumentTypeResource::nextIdentifier())->toBe('DT00001');

    DocumentType::create(['name' => 'Two', 'identifier' => 'DT00002']);
    expect(DocumentTypeResource::nextIdentifier())->toBe('DT00003');
});

it('EDGE: nextIdentifier takes the max numeric value across mixed padding', function () {
    DocumentType::create(['name' => 'A', 'identifier' => 'DT1']);
    DocumentType::create(['name' => 'B', 'identifier' => 'DT00007']);
    expect(DocumentTypeResource::nextIdentifier())->toBe('DT00008');
});

it('EDGE: nextIdentifier does not truncate past 5 digits (DT99999 -> DT100000)', function () {
    DocumentType::create(['name' => 'Big', 'identifier' => 'DT99999']);
    expect(DocumentTypeResource::nextIdentifier())->toBe('DT100000');
});

it('EDGE: a duplicate identifier is rejected on create (unique)', function () {
    $this->actingAs(dt_superAdmin());
    DocumentType::create(['name' => 'First', 'identifier' => 'DT00001']);

    Livewire::test(CreateDocumentType::class)
        ->fillForm(['identifier' => 'DT00001', 'name' => 'Second'])
        ->call('create')
        ->assertHasFormErrors(['identifier']);

    expect(DocumentType::where('identifier', 'DT00001')->count())->toBe(1);
});

it('EDGE: bulk delete actually removes the selected rows', function () {
    $this->actingAs(dt_superAdmin());
    $a = DocumentType::create(['name' => 'A', 'identifier' => 'DT00001']);
    $b = DocumentType::create(['name' => 'B', 'identifier' => 'DT00002']);

    Livewire::test(ListDocumentTypes::class)
        ->callTableBulkAction('delete', [$a, $b]);

    expect(DocumentType::whereIn('id', [$a->id, $b->id])->count())->toBe(0);
});

it('EDGE: deleting a referenced Document Type nulls the FK, never deletes the document', function () {
    $this->actingAs(dt_superAdmin());

    // A document that points at the type via the soft FK (document_type_id).
    $repo = Repository::factory()->create(['code' => 'DTR_' . substr(uniqid(), -6)]);
    $series = Series::create([
        'code' => 'DTR_' . substr(uniqid(), -4),
        'title' => 'DTR series',
        'is_active' => true,
    ]);
    $type = DocumentType::create(['name' => 'Deed', 'identifier' => 'DT00001']);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DTR-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Deed', // legacy free-text label survives the delete
        'document_type_id' => $type->id,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);

    Livewire::test(ListDocumentTypes::class)
        ->callTableBulkAction('delete', [$type]);

    // Type gone; document intact with the structured link nulled (nullOnDelete),
    // and the human-readable label untouched — no cascade, no FK error.
    $doc->refresh();
    expect(DocumentType::whereKey($type->id)->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->whereKey($doc->id)->exists())->toBeTrue()
        ->and($doc->document_type_id)->toBeNull()
        ->and($doc->document_type)->toBe('Deed');
});
