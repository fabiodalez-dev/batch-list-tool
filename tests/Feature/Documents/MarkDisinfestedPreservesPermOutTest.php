<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * B2 — MarkDisinfested must not silently revert PERM_OUT.
 *
 * A PERM_OUT document has been permanently transferred out of the archive.
 * Running "Mark disinfested" (e.g. in a bulk operation that sweeps multiple
 * statuses at once) must stamp the disinfestation_date and clear the
 * is_in_disinfestation flag, but MUST NOT pull the barcode_status back to IN.
 *
 * The complementary positive test confirms that a non-PERM_OUT document (e.g.
 * OUT while in the fumigation chamber) IS correctly returned to IN — the guard
 * must be precise, not over-broad.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* -------------------------------------------------------------------------
 |  Local helpers — mirroring DocumentDisinfestationWorkflowTest pattern to
 |  stay self-contained and avoid global helper name collisions.
 * ------------------------------------------------------------------------- */

function permout_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'permout-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function permout_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'PO_' . substr(uniqid(), -6),
    ]);
}

function permout_series(): Series
{
    return Series::firstOrCreate(
        ['code' => 'POS_' . substr(uniqid(), -4)],
        ['title' => 'PermOut test series', 'is_active' => true],
    );
}

function permout_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'PODOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'Register',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/**
 * Reflection helper — drives the real action closure without a browser/Livewire
 * component, giving us the fastest possible feedback loop while still exercising
 * the production code path (not a manual simulation).
 */
function permout_runAction(Action $action, array $named): void
{
    $closure = (function () {
        return $this->action;
    })->call($action);

    if (! $closure instanceof Closure) {
        throw new RuntimeException('Action closure missing');
    }

    $ref = new ReflectionFunction($closure);
    $args = [];
    foreach ($ref->getParameters() as $p) {
        $name = $p->getName();
        if (array_key_exists($name, $named)) {
            $args[] = $named[$name];
            continue;
        }
        if ($p->isOptional()) {
            $args[] = $p->getDefaultValue();
            continue;
        }

        throw new RuntimeException("Cannot bind action parameter: {$name}");
    }
    $closure(...$args);
}

/* -------------------------------------------------------------------------
 |  Test 1 — PERM_OUT is preserved (the bug guard)
 * ------------------------------------------------------------------------- */

it('keeps PERM_OUT when disinfesting a permanently-out document', function () {
    $this->actingAs(permout_actAsSuperAdmin());

    $repo = permout_repo();
    $series = permout_series();

    // A PERM_OUT document must already have a disinfestation_date (enforced by
    // the model-level guard from another task — we set it here to satisfy that
    // constraint and keep this test focused on the B2 invariant only).
    $doc = permout_doc($repo->id, $series->id, [
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => now()->subDay()->toDateString(),
        'is_in_disinfestation' => false,
    ]);

    $today = now()->toDateString();
    permout_runAction(MarkDisinfestedAction::make(), [
        'record' => $doc,
        'data' => ['disinfestation_date' => $today],
    ]);

    $doc->refresh();

    // The disinfestation_date must be updated and the flag cleared …
    expect($doc->disinfestation_date?->toDateString())->toBe($today)
        ->and($doc->is_in_disinfestation)->toBeFalse()
        // … but PERM_OUT must NOT have been overwritten with IN.
        ->and($doc->barcode_status)->toBe('PERM_OUT');
});

/* -------------------------------------------------------------------------
 |  Test 2 — Non-PERM_OUT still transitions to IN (positive path)
 * ------------------------------------------------------------------------- */

it('still sets IN when disinfesting a non-PERM_OUT document', function () {
    $this->actingAs(permout_actAsSuperAdmin());

    $repo = permout_repo();
    $series = permout_series();

    $doc = permout_doc($repo->id, $series->id, [
        'barcode_status' => 'OUT',
        'is_in_disinfestation' => true,
        'disinfestation_date' => null,
    ]);

    $today = now()->toDateString();
    permout_runAction(MarkDisinfestedAction::make(), [
        'record' => $doc,
        'data' => ['disinfestation_date' => $today],
    ]);

    $doc->refresh();

    expect($doc->barcode_status)->toBe('IN')
        ->and($doc->is_in_disinfestation)->toBeFalse()
        ->and($doc->disinfestation_date?->toDateString())->toBe($today);
});
