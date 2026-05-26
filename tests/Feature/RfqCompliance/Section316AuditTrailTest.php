<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Models\Audit;

/**
 * RFQ §3.1.5 / §3.1.6 — Full audit trail of all changes (Old/New/User/Timestamp).
 *
 * Eight tests pinning the contract that every change writes an audit row
 * with the required quartet.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    config(['audit.console' => true]);
    Document::observe(AuditableObserver::class);
    Batch::observe(AuditableObserver::class);
    Box::observe(AuditableObserver::class);
});

function s316_setup(): array
{
    $repo = Repository::factory()->create(['code' => 'S316-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'S316S-' . substr(uniqid(), -4), 'title' => 'S316', 'is_active' => true]);

    return [$repo, $series];
}

it('§ 3.1.6 #1: Document model implements OwenIt\\Auditing\\Contracts\\Auditable', function () {
    expect(new Document)->toBeInstanceOf(AuditableContract::class);
});

it('§ 3.1.6 #2: Batch model implements OwenIt\\Auditing\\Contracts\\Auditable', function () {
    expect(new Batch)->toBeInstanceOf(AuditableContract::class);
});

it('§ 3.1.6 #3: Box model implements OwenIt\\Auditing\\Contracts\\Auditable', function () {
    expect(new Box)->toBeInstanceOf(AuditableContract::class);
});

it('§ 3.1.6 #4: Updating a Document yields an audit row with old_values + new_values', function () {
    [$repo, $series] = s316_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AT-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'notes' => 'old text',
    ]);
    $doc->update(['notes' => 'new text']);
    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'updated')
        ->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->old_values['notes'] ?? null)->toBe('old text')
        ->and($audit->new_values['notes'] ?? null)->toBe('new text');
});

it('§ 3.1.6 #5: Audit row records the acting user when authenticated', function () {
    $u = User::factory()->create(['email' => 's316-' . uniqid() . '@t.t']);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    [$repo, $series] = s316_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AT2-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'notes' => 'init',
    ]);
    $doc->update(['notes' => 'changed']);
    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->where('event', 'updated')
        ->latest('id')->first();
    expect((int) $audit->user_id)->toBe((int) $u->id);
});

it('§ 3.1.6 #6: Audit row stamps the change with a timestamp (created_at)', function () {
    [$repo, $series] = s316_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AT3-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    $doc->update(['notes' => 'ts test']);
    $audit = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->latest('id')->first();
    expect($audit->created_at)->not->toBeNull();
});

it('§ 3.1.6 #7: Batch update writes an audit row', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 6000 + random_int(0, 99),
        'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id,
        'is_active' => true, 'description' => 'one',
    ]);
    $before = Audit::query()->where('auditable_type', Batch::class)->where('auditable_id', $batch->id)->count();
    $batch->update(['description' => 'two']);
    $after = Audit::query()->where('auditable_type', Batch::class)->where('auditable_id', $batch->id)->count();
    expect($after)->toBeGreaterThan($before);
});

it('§ 3.1.6 #8: Audit row event field is one of created/updated/restored/deleted', function () {
    [$repo, $series] = s316_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'AT4-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    $doc->update(['notes' => 'ev']);
    $rows = Audit::query()
        ->where('auditable_type', Document::class)
        ->where('auditable_id', $doc->id)
        ->pluck('event')->all();
    foreach ($rows as $event) {
        expect(in_array($event, ['created', 'updated', 'restored', 'deleted'], true))->toBeTrue();
    }
});
