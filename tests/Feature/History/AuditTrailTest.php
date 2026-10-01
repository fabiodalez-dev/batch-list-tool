<?php

declare(strict_types=1);

use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.5 — every change with its old value, new value, user and time,
 * readable on screen and exportable.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['audit.console' => true]);
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'ATR']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id, 'name' => 'Charlene Test']);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);

    $this->b27 = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '27', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->b29 = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '29', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $this->box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '223', 'batch_id' => $this->b27->id, 'barcode' => 'AA34092', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $this->box->update(['batch_id' => $this->b29->id, 'tracking_note' => 'At NRA as from 6/3/2024']);
});

it('names the record and spells out each change, ids resolved', function (): void {
    $audit = Audit::query()->where('auditable_type', Box::class)->where('event', 'updated')->firstOrFail();

    Livewire::test(ListAudits::class)
        ->assertCanSeeTableRecords([$audit])
        ->assertSee('RAS 223 · AA34092')
        ->assertSee('Batch: 27 → 29')
        ->assertSee('At NRA as from 6/3/2024');
});

it('filters the trail by the field that changed and by who changed it', function (): void {
    $batchChange = Audit::query()->where('auditable_type', Box::class)->where('event', 'updated')->firstOrFail();
    $created = Audit::query()->where('auditable_type', Box::class)->where('event', 'created')->firstOrFail();

    Livewire::test(ListAudits::class)
        ->filterTable('field', ['field' => 'tracking_note'])
        ->assertCanSeeTableRecords([$batchChange])
        ->assertCanNotSeeTableRecords([$created]);

    Livewire::test(ListAudits::class)
        ->filterTable('field', ['field' => 'seal_number'])
        ->assertCanNotSeeTableRecords([$batchChange]);

    $someoneElse = User::factory()->create();
    Livewire::test(ListAudits::class)
        ->filterTable('user_id', $someoneElse->id)
        ->assertCanNotSeeTableRecords([$batchChange]);
});

it('exports one row per changed field, old and new value side by side', function (): void {
    $component = Livewire::test(ListAudits::class)
        ->filterTable('record_id', ['id' => $this->box->id])
        ->callAction('export_csv');
    $csv = (string) base64_decode((string) $component->effects['download']['content'], true);
    $rows = array_map(fn (string $l): array => str_getcsv($l, escape: '\\'), array_values(array_filter(explode("\n", preg_replace('/^\x{FEFF}/u', '', $csv) ?? $csv))));

    expect($rows[0])->toBe(['When', 'Who', 'Event', 'Record type', 'Record', 'Field', 'Old value', 'New value', 'IP address']);

    $batchRow = collect($rows)->first(fn (array $r): bool => ($r[5] ?? '') === 'Batch');
    expect($batchRow)->not->toBeNull()
        ->and([$batchRow[1], $batchRow[2], $batchRow[4], $batchRow[6], $batchRow[7]])->toBe(['Charlene Test', 'updated', 'RAS 223 · AA34092', '27', '29']);

    // The creation is one row of its own, with no field.
    expect(collect($rows)->contains(fn (array $r): bool => ($r[2] ?? '') === 'created' && ($r[5] ?? 'x') === ''))->toBeTrue();
});
