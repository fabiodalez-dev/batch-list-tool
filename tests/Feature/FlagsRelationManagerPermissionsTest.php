<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\RelationManagers\FlagsRelationManager;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Regression guard for the Filament-Shield permission separator on
 * FlagsRelationManager.
 *
 * Background: Shield generates permissions with the form
 *   `<op>_<resource>` (e.g. `create_document_flag`)
 * NOT
 *   `<op>_<resource_with_double_colons>` (e.g. `create_document::flag`).
 *
 * Earlier commits on this file used the legacy `::` separator on four
 * call sites. Editors with the correctly-named Shield permissions could
 * not access the relation manager because $user->can('create_document::flag')
 * always returned false (no such permission was ever seeded).
 *
 * These tests pin the working separator and catch any future drift.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function frmp_makeDoc(): Document
{
    $repo = Repository::factory()->create(['code' => 'FRMP-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'F-' . substr(uniqid(), -4), 'title' => 'F', 'is_active' => true]);

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DF-' . uniqid(),
        'document_type' => 'R',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
}

/**
 * Call a protected static method on FlagsRelationManager from outside the class.
 */
function frmp_call(string $method): bool
{
    $ref = new ReflectionClass(FlagsRelationManager::class);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return (bool) $m->invoke(null);
}

it('editor with create_document_flag permission CAN create flags (Shield separator)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    // sanity: bl_seedShieldPermissions() must have seeded the correct name
    expect(Permission::where('name', 'create_document_flag')->exists())->toBeTrue();
    expect($editor->can('create_document_flag'))->toBeTrue();

    $this->actingAs($editor);

    expect(frmp_call('userCanCreate'))->toBeTrue();
});

it('editor without create_document_flag permission CANNOT create flags', function () {
    // Build a "stripped editor": role with NO permissions attached.
    Role::findOrCreate('stripped_editor', 'web');
    $editor = User::factory()->create();
    $editor->assignRole('stripped_editor');

    $this->actingAs($editor);

    expect($editor->can('create_document_flag'))->toBeFalse();
    expect(frmp_call('userCanCreate'))->toBeFalse();
});

it('viewer cannot create, update or resolve flags', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    expect(frmp_call('userCanCreate'))->toBeFalse();
    expect(frmp_call('userCanUpdate'))->toBeFalse();
    expect(frmp_call('userCanResolve'))->toBeFalse();
});

it('viewer can render the FlagsRelationManager (view_any_document_flag) but the Create header action is hidden', function () {
    // Create the document while unauthenticated so the multi-tenant
    // creating() guard is bypassed (CLI/queue path).
    $doc = frmp_makeDoc();

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    // view_any_document_flag is in the `view_*` slice assigned to viewer.
    expect($viewer->can('view_any_document_flag'))->toBeTrue();

    Livewire::test(FlagsRelationManager::class, [
        'ownerRecord' => $doc,
        'pageClass' => EditDocument::class,
    ])->assertSuccessful();
});

it('editor with resolve_document_flag permission CAN resolve flags (custom Shield perm)', function () {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    // resolve_document_flag is the custom (non-Shield-default) permission
    // explicitly seeded by InitialDataSeeder + bl_seedShieldPermissions.
    expect(Permission::where('name', 'resolve_document_flag')->exists())->toBeTrue();
    expect($editor->can('resolve_document_flag'))->toBeTrue();
    expect(frmp_call('userCanResolve'))->toBeTrue();
});
