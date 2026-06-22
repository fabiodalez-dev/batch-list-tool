<?php

declare(strict_types=1);

use App\Models\LocationType;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BatchType;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Lookup\FlagType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Models\Audit;

/**
 * NAF Feedback-1 comment #4 — the lookup pages (Barcode Statuses, Box Types,
 * Flag Types, Current Box Types, Accession Types, Location Types) must show an
 * "Inputter" column. That column (App\Filament\Support\CreatorColumn) resolves
 * the creator via the OwenIt audit trail, so each lookup model must be Auditable
 * and creating a row must stamp a 'created' audit linked to the acting user.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // OwenIt disables auditing in console (audit.console=false) — enable it so
    // the 'created' event is recorded during the test, mirroring web requests.
    config(['audit.console' => true]);
});

it('every lookup model implements the Auditable contract', function () {
    foreach ([BarcodeStatus::class, BoxType::class, FlagType::class, CurrentBoxType::class, BatchType::class, LocationType::class] as $model) {
        expect(new $model)->toBeInstanceOf(AuditableContract::class);
    }
});

it('stamps the acting user as the creator audit on a new lookup row', function () {
    /** @var User $user */
    $user = User::factory()->create(['name' => 'Mariapia Aquilina', 'is_active' => true]);
    $this->actingAs($user);

    $flag = FlagType::create(['code' => 'TST', 'label' => 'Test flag', 'sort_order' => 99]);

    $audit = Audit::query()
        ->where('auditable_type', FlagType::class)
        ->where('auditable_id', $flag->id)
        ->where('event', 'created')
        ->first();

    expect($audit)->not->toBeNull()
        ->and((int) $audit->user_id)->toBe((int) $user->id)
        ->and($audit->user?->name)->toBe('Mariapia Aquilina');
});
