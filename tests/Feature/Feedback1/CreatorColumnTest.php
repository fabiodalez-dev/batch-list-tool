<?php

declare(strict_types=1);

use App\Filament\Support\CreatorColumn;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;

/**
 * A9 (Wave A) — CreatorColumn helper tests.
 *
 * Verifies that:
 *  1. ::make() returns a TextColumn labelled "Inputter".
 *  2. The state closure resolves the creator name from the first audit entry.
 *  3. Null-safe: records with no audit entry return null (rendered as '—').
 *  4. The column is toggleable.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// 1. Label is 'Inputter'
// ---------------------------------------------------------------------------

it('returns a TextColumn labelled Inputter', function () {
    $col = CreatorColumn::make();

    expect($col)->toBeInstanceOf(TextColumn::class)
        ->and($col->getLabel())->toBe('Inputter');
});

// ---------------------------------------------------------------------------
// 2. Resolves the creator name from the first (created) audit entry
// ---------------------------------------------------------------------------

it('resolves the creator name from the first created audit', function () {
    // Owen-it's observer is booted with audit.console=false (the default),
    // so it does not attach in CLI/test mode.  Flip the config and
    // re-observe so the pipeline runs exactly as in HTTP context.
    config(['audit.console' => true]);
    Batch::observe(AuditableObserver::class);

    $creator = User::factory()->create(['name' => 'Alice Tester']);
    $creator->assignRole('super_admin'); // bypass multi-tenant check
    $this->actingAs($creator);

    $repo = Repository::factory()->create();
    $creator->update(['default_repository_id' => $repo->id]);

    // Creating a Batch while acting as $creator will trigger the OwenIt
    // Auditable trait to write an audit row with event='created' and the
    // user morph pointing to $creator.
    $batch = Batch::factory()->create([
        'repository_id' => $repo->id,
        'batch_number' => 1001,
    ]);

    $col = CreatorColumn::make();

    // Retrieve the stored closure via the public getter and invoke it with
    // the batch record. Filament evaluates it via a dependency-injection
    // wrapper; for a simple test we call it directly.
    $closure = $col->getGetStateUsingCallback();
    $state = $closure($batch);

    expect($state)->toBe('Alice Tester');
});

// ---------------------------------------------------------------------------
// 2b. Falls back to the earliest audit when there is NO 'created' audit
// ---------------------------------------------------------------------------

it('falls back to the earliest audit user when the record has no created audit', function () {
    // The client's records were bulk-created before auditing was wired into the
    // import path, so most carry NO 'created' audit — a re-import only UPDATES
    // them (an 'updated' audit). Keying strictly on 'created' left the Inputter
    // column blank for the vast majority (e.g. all 678 authorities). The column
    // must fall back to the earliest audit of ANY event so it shows the operator
    // who entered the record's data.
    $editor = User::factory()->create(['name' => 'Charlene Ellul']);
    $editor->assignRole('super_admin');
    $this->actingAs($editor);

    $repo = Repository::factory()->create();
    $editor->update(['default_repository_id' => $repo->id]);

    // Create the batch with auditing OFF (mimics the legacy bulk-created record
    // that never got a 'created' audit)...
    Audit::$auditingGloballyDisabled = true;
    $batch = Batch::factory()->create(['repository_id' => $repo->id, 'batch_number' => 3001]);
    Audit::$auditingGloballyDisabled = false;

    // ...then write a single 'updated' audit attributed to $editor, exactly like
    // a re-import would (no 'created' audit exists for this record).
    $batch->audits()->create([
        'event' => 'updated',
        'auditable_type' => $batch::class,
        'auditable_id' => $batch->getKey(),
        'user_type' => $editor::class,
        'user_id' => $editor->getKey(),
        'old_values' => [],
        'new_values' => [],
    ]);

    expect($batch->audits()->where('event', 'created')->count())->toBe(0);

    $col = CreatorColumn::make();
    $state = ($col->getGetStateUsingCallback())($batch->fresh());

    expect($state)->toBe('Charlene Ellul');
});

// ---------------------------------------------------------------------------
// 3. Null-safe when no audit entry exists
// ---------------------------------------------------------------------------

it('returns null when the record has no audit entry', function () {
    // Disable auditing globally for this test so no audit row is written.
    Audit::$auditingGloballyDisabled = true;

    // Wrap in try/finally so the global flag is always restored even if an
    // assertion or factory call throws — preventing state bleed into other tests.
    try {
        // Use super_admin so the multi-tenant guard is bypassed (admin roles
        // skip the repository membership check in BelongsToRepository).
        $sa = User::factory()->create(['name' => 'Super Tester']);
        $sa->assignRole('super_admin');
        $this->actingAs($sa);

        $repo = Repository::factory()->create();
        $batch = Batch::factory()->create([
            'repository_id' => $repo->id,
            'batch_number' => 2001,
        ]);

        // Sanity check: no audits exist.
        expect($batch->audits()->count())->toBe(0);

        $col = CreatorColumn::make();
        $closure = $col->getGetStateUsingCallback();
        $state = $closure($batch);

        expect($state)->toBeNull();
    } finally {
        // Always restore — even when an exception propagates.
        Audit::$auditingGloballyDisabled = false;
    }
});

// ---------------------------------------------------------------------------
// 4. Column is toggleable (default visible)
// ---------------------------------------------------------------------------

it('is toggleable', function () {
    $col = CreatorColumn::make();

    // Filament TextColumn exposes isToggleable() after calling toggleable().
    expect($col->isToggleable())->toBeTrue();
});
