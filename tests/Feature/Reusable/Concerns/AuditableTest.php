<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;

/**
 * Reusable: owen-it/laravel-auditing shape contract.
 *
 * Pins the audit row shape (old/new values, user_id, ip, ua) so the audit
 * trail keeps the structure other tests / features depend on.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    config(['audit.console' => true]);
    // Owen-it boots the observer once per process — re-attach so flipping
    // audit.console=true mid-test still produces rows.
    Document::observe(AuditableObserver::class);
    Batch::observe(AuditableObserver::class);
    User::observe(AuditableObserver::class);
});

function aud_makeDoc(): Document
{
    $repo = Repository::factory()->create(['code' => 'AUD-' . substr(uniqid(), -4)]);
    $series = Series::query()->first() ?? Series::create([
        'code' => 'AUDS-' . substr(uniqid(), -4),
        'title' => 'AUD series',
        'is_active' => true,
    ]);

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AUD-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'notes' => 'before',
    ]);
}

it('Auditable: update on Document creates an audit row', function () {
    $doc = aud_makeDoc();
    $before = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->count();
    $doc->update(['notes' => 'after']);
    $after = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->count();
    expect($after)->toBeGreaterThan($before);
});

it('Auditable: audit row records old_values and new_values', function () {
    $doc = aud_makeDoc();
    $doc->update(['notes' => 'after-change']);
    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'updated')
        ->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->old_values)->toBeArray()
        ->and($audit->new_values)->toBeArray()
        ->and($audit->old_values['notes'] ?? null)->toBe('before')
        ->and($audit->new_values['notes'] ?? null)->toBe('after-change');
});

it('Auditable: user_id is captured when an authenticated user updates', function () {
    $u = User::factory()->create(['email' => 'aud-' . uniqid() . '@t.t']);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    $doc = aud_makeDoc();
    $doc->update(['notes' => 'as-user']);
    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'updated')
        ->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->user_id)->toBe((int) $u->id);
});

it('Auditable: audit row records the audit event name (created/updated)', function () {
    $doc = aud_makeDoc();
    $doc->update(['notes' => 'evt']);
    $row = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and(in_array($row->event, ['created', 'updated', 'restored', 'deleted'], true))->toBeTrue();
});

it('Auditable: Batch model is auditable on update', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 9000 + random_int(1, 99),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
        'description' => 'a',
    ]);
    $before = Audit::query()->where('auditable_type', Batch::class)->where('auditable_id', $batch->id)->count();
    $batch->update(['description' => 'b']);
    $after = Audit::query()->where('auditable_type', Batch::class)->where('auditable_id', $batch->id)->count();
    expect($after)->toBeGreaterThan($before);
});

it('Auditable: User model excludes password from new_values via $auditExclude', function () {
    $u = User::factory()->create();
    $u->update(['name' => 'Renamed', 'password' => 'new-secret-' . uniqid()]);
    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $u->id)
        ->where('event', 'updated')
        ->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and(array_key_exists('password', $audit->new_values ?? []))->toBeFalse();
});
