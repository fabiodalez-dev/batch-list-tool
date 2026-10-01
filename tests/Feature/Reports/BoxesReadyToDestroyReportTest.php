<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\BoxesReadyToDestroyReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * RFQ Appendix 2 §vii — the boxes whose documents are all catalogued, listed
 * by the same rule the box's own Destroy button applies.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'BRD']);
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $u->assignRole('super_admin');
    $this->actingAs($u);
    $this->series = Series::create(['code' => 'BRDS', 'title' => 'S', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9401', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);

    $box = fn (string $n, array $a = []): Box => Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge(['box_type' => 'RAS', 'box_number' => $n, 'batch_id' => $this->batch->id, 'barcode' => 'BR' . $n, 'barcode_status' => 'IN', 'is_legacy' => false], $a));
    $doc = fn (Box $b, ?string $cat, string $id): Document => Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => $id, 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'T', 'current_box_id' => $b->id, 'catalogue_identifier' => $cat]);

    $this->done = $box('A1');
    $doc($this->done, 'CAT-1', 'BRD-1');
    $doc($this->done, 'CAT-2', 'BRD-2');

    $this->half = $box('A2');
    $doc($this->half, 'CAT-3', 'BRD-3');
    $doc($this->half, null, 'BRD-4');

    $this->trashedBlocker = $box('A3');
    $doc($this->trashedBlocker, 'CAT-5', 'BRD-5');
    $doc($this->trashedBlocker, null, 'BRD-6')->delete();

    // A document cannot be put INTO a destroyed box (model guard), so the box
    // is destroyed after its document arrived — the real order of events.
    $this->destroyed = $box('A4');
    $doc($this->destroyed, 'CAT-7', 'BRD-7');
    $this->destroyed->update(['destroyed_at' => now()]);

    $this->empty = $box('A5');
});

it('lists only the boxes whose documents are all catalogued', function (): void {
    Livewire::test(BoxesReadyToDestroyReport::class)
        ->assertCanSeeTableRecords([$this->done])
        ->assertCanNotSeeTableRecords([$this->half, $this->trashedBlocker, $this->destroyed, $this->empty]);
});

it('agrees with the Destroy button on every box that has documents', function (): void {
    $listed = Livewire::test(BoxesReadyToDestroyReport::class)->instance()->getFilteredTableQuery()->pluck('boxes.id')->all();

    foreach ([$this->done, $this->half, $this->trashedBlocker, $this->destroyed] as $box) {
        expect(in_array($box->id, $listed, true))->toBe($box->canBeDestroyed()['ok'], "box {$box->box_number}");
    }
});

it('brings in the empty boxes on request', function (): void {
    Livewire::test(BoxesReadyToDestroyReport::class)
        ->filterTable('include_empty', true)
        ->assertCanSeeTableRecords([$this->done, $this->empty])
        ->assertCanNotSeeTableRecords([$this->half]);
});

it('exports the list it shows', function (): void {
    $component = Livewire::test(BoxesReadyToDestroyReport::class)->callAction('exportCsv');
    $csv = (string) base64_decode((string) $component->effects['download']['content'], true);

    expect($csv)->toContain('"Box type",Box,Batch,Barcode,Location,Documents')
        ->toContain('RAS,A1,9401,BRA1')
        ->not->toContain('BRA2')
        ->not->toContain('BRA5');
});
