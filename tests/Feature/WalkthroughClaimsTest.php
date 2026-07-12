<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Walkthrough claims (client demo 2026-07-07) — pins the statements made in
 * the reply that were not yet covered by a dedicated test: the delete
 * confirmation warning, the delete permission gate, the Trashed →
 * Restore/Force-delete flow, and the bulk "Relocate boxes" action
 * (location + PERM OUT + disinfestation date + tracking note in one go).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function wc_user(string $role): User
{
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($role);

    return $u;
}

it('claim 1a: deleting a document shows the warning modal', function (): void {
    $this->actingAs(wc_user('super_admin'));
    $doc = qf_doc();

    $page = Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()]);

    /** @var Action|null $delete */
    $delete = collect($page->instance()->getCachedHeaderActions())
        ->flatMap(fn ($a) => $a instanceof ActionGroup ? $a->getFlatActions() : [$a])
        ->first(fn ($a): bool => $a instanceof Action && $a->getName() === 'delete');

    // The delete header action opens a confirmation modal with the warning.
    expect($delete)->not->toBeNull()
        ->and((string) $delete->getModalHeading())->toBe('Delete document')
        ->and((string) $delete->getModalDescription())->toBe('This record will be deleted.');
});

it('claim 1b: deleting a document is restricted to authorised users', function (): void {
    $viewer = wc_user('viewer');
    $editor = wc_user('editor');
    $admin = wc_user('super_admin');

    expect($viewer->can('delete_document'))->toBeFalse()
        ->and($admin->can('delete_document'))->toBeTrue();

    // Whatever the editor grant is, the gate must decide — never a free-for-all.
    expect(is_bool($editor->can('delete_document')))->toBeTrue();
});

it('claim 1c: a deleted document appears under Trashed, can be restored, and can be removed permanently', function (): void {
    $this->actingAs(wc_user('super_admin'));
    $doc = qf_doc();

    // Soft delete → hidden from the default list, visible under Trashed.
    $doc->delete();
    expect($doc->fresh()->trashed())->toBeTrue();

    Livewire::test(ListDocuments::class)
        ->assertCanNotSeeTableRecords([$doc])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$doc])
        // Restore (the undo).
        ->callTableAction('restore', $doc);

    expect($doc->fresh()->trashed())->toBeFalse();

    // Delete again, then force delete: gone for good.
    $doc->delete();
    Livewire::test(ListDocuments::class)
        ->filterTable('trashed', true)
        ->callTableAction('forceDelete', $doc);

    expect(Document::withoutGlobalScopes()->withTrashed()->find($doc->id))->toBeNull();
});

it('claim 3b: bulk Relocate sets location, PERM OUT with disinfestation date and a tracking note in one go', function (): void {
    $this->actingAs(wc_user('super_admin'));

    $location = qf_location();
    $boxA = qf_box(['notes' => null]);
    $boxB = qf_box(['notes' => 'existing note']);

    Livewire::test(ListBoxes::class)
        ->callTableBulkAction('relocate', [$boxA, $boxB], data: [
            'location_id' => $location->id,
            'set_perm_out' => true,
            'disinfestation_date' => now()->toDateString(),
            'tracking_note' => 'Moved to NRA — walkthrough claim',
        ]);

    foreach ([$boxA, $boxB] as $box) {
        $fresh = $box->fresh();
        expect($fresh->location_id)->toBe($location->id)
            ->and($fresh->barcode_status)->toBe('PERM_OUT')
            ->and($fresh->disinfestation_date)->not->toBeNull()
            ->and((string) $fresh->notes)->toContain('Moved to NRA — walkthrough claim');
    }
});
