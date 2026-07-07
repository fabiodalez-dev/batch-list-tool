<?php

declare(strict_types=1);

use App\Filament\Resources\Lookups\BoxTypeResource\Pages\ListBoxTypes;
use App\Models\Lookup\BoxType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Regression (schema/query review 2026-07-07) — CreatorColumn resolves the
 * inputter from the first 'created' audit via a state closure, which Filament
 * does NOT auto-eager-load (unlike dotted relationship columns). Without an
 * explicit ->with(['audits' => …]) every rendered row fired its own audit
 * query: 10 rows = 10 extra queries on each Lookup table. The lookup
 * resources now eager-load audits.user; this pins that.
 */
uses(RefreshDatabase::class);

it('renders a Lookup table with CreatorColumn without one audit query per row', function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    for ($i = 0; $i < 10; $i++) {
        BoxType::firstOrCreate(['code' => 'N1P' . $i], ['label' => 'Probe ' . $i, 'sort_order' => 90 + $i, 'is_active' => true]);
    }

    DB::enableQueryLog();
    Livewire::test(ListBoxTypes::class)->assertOk();
    $audits = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'audits'));
    DB::disableQueryLog();

    // One eager-load query, not one per row.
    expect(count($audits))->toBeLessThanOrEqual(1);
});
