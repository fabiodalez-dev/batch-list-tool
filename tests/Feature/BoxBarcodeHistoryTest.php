<?php

use App\Filament\Resources\BoxResource;
use App\Filament\Resources\BoxResource\RelationManagers\BarcodeHistoryRelationManager;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/*
|--------------------------------------------------------------------------
| RFQ §3.1.5 — Box barcode history (mirror of PR #29 / IdentifierHistoryTest)
|--------------------------------------------------------------------------
|
| Covers migration schema, model contract, the booted() observer that
| auto-captures barcode + barcode_status transitions on Box updates, the
| Filament RelationManager wiring, multi-tenant isolation derived from
| Box.batch.repository_id, and the factory.
|
*/

// -- helpers -----------------------------------------------------------------

/**
 * Build a Box and ensure the auth user (if any) can write to its tenant.
 */
function makeBoxForHistory(array $overrides = []): Box
{
    return Box::factory()->create($overrides);
}

// 1 ---------------------------------------------------------------------------
test('migration creates box_barcode_history table with all expected columns', function () {
    expect(Schema::hasTable('box_barcode_history'))->toBeTrue();

    foreach ([
        'id',
        'box_id',
        'previous_barcode',
        'new_barcode',
        'previous_status',
        'new_status',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
        'created_at',
        'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('box_barcode_history', $col))
            ->toBeTrue("Missing column: {$col}");
    }
});

// 2 ---------------------------------------------------------------------------
test('model exposes the correct fillable and casts', function () {
    $m = new BoxBarcodeHistory;

    expect($m->getFillable())->toEqualCanonicalizing([
        'box_id',
        'previous_barcode',
        'new_barcode',
        'previous_status',
        'new_status',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
    ]);

    expect($m->getCasts())->toHaveKey('changed_at');
    expect($m->getCasts()['changed_at'])->toBe('datetime');
});

// 3 ---------------------------------------------------------------------------
test('changing Box barcode creates a history row with previous + new + repository_id', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = makeBoxForHistory(['batch_id' => $batch->id, 'barcode' => 'BC-AAA-001']);

    $box->update(['barcode' => 'BC-BBB-002']);

    $rows = BoxBarcodeHistory::where('box_id', $box->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('BC-AAA-001');
    expect($rows->first()->new_barcode)->toBe('BC-BBB-002');
    expect($rows->first()->repository_id)->toBe($repo->id);
});

// 4 ---------------------------------------------------------------------------
test('changing only barcode_status creates a history row', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-S-1', 'barcode_status' => 'IN']);

    $box->update(['barcode_status' => 'OUT']);

    $rows = BoxBarcodeHistory::where('box_id', $box->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_status)->toBe('IN');
    expect($rows->first()->new_status)->toBe('OUT');
    // Barcode itself didn't change → previous == new (string snapshot).
    expect($rows->first()->previous_barcode)->toBe('BC-S-1');
    expect($rows->first()->new_barcode)->toBe('BC-S-1');
});

// 5 ---------------------------------------------------------------------------
test('changing both barcode AND status produces exactly ONE history row', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-X-1', 'barcode_status' => 'IN']);

    $box->update(['barcode' => 'BC-X-2', 'barcode_status' => 'OUT']);

    $rows = BoxBarcodeHistory::where('box_id', $box->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->previous_barcode)->toBe('BC-X-1');
    expect($rows->first()->new_barcode)->toBe('BC-X-2');
    expect($rows->first()->previous_status)->toBe('IN');
    expect($rows->first()->new_status)->toBe('OUT');
});

// 6 ---------------------------------------------------------------------------
test('updating an unrelated column does NOT create a history row', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-N-1']);

    $box->update(['notes' => 'just a note']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(0);
});

// 7 ---------------------------------------------------------------------------
test('observer does NOT record on initial create', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-FRESH']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(0);
});

// 8 ---------------------------------------------------------------------------
test('whitespace-only barcode change is ignored when status is unchanged', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-WS-1', 'barcode_status' => 'IN']);

    $box->update(['barcode' => '  BC-WS-1  ']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(0);
});

// 9 ---------------------------------------------------------------------------
test('multiple changes produce multiple rows in chronological order', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-MULTI-0']);

    $box->update(['barcode' => 'BC-MULTI-A']);
    $box->refresh();
    $box->update(['barcode' => 'BC-MULTI-B']);
    $box->refresh();
    $box->update(['barcode' => 'BC-MULTI-C']);

    $rows = $box->barcodeHistory()->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('previous_barcode')->all())
        ->toBe(['BC-MULTI-0', 'BC-MULTI-A', 'BC-MULTI-B']);
    expect($rows->pluck('new_barcode')->all())
        ->toBe(['BC-MULTI-A', 'BC-MULTI-B', 'BC-MULTI-C']);
});

// 10 --------------------------------------------------------------------------
test('previousBarcodes() returns distinct values only', function () {
    $box = makeBoxForHistory();

    BoxBarcodeHistory::factory()->create([
        'box_id' => $box->id,
        'previous_barcode' => 'BC-OLD-X',
    ]);
    BoxBarcodeHistory::factory()->create([
        'box_id' => $box->id,
        'previous_barcode' => 'BC-OLD-X',
    ]);
    BoxBarcodeHistory::factory()->create([
        'box_id' => $box->id,
        'previous_barcode' => 'BC-OLD-Y',
    ]);

    expect($box->previousBarcodes()->all())
        ->toEqualCanonicalizing(['BC-OLD-X', 'BC-OLD-Y']);
});

// 11 --------------------------------------------------------------------------
test('barcodeHistory relation is ordered descending by changed_at', function () {
    $box = makeBoxForHistory();

    $a = BoxBarcodeHistory::factory()
        ->backDatedTo(now()->subDays(10))
        ->create(['box_id' => $box->id]);

    $b = BoxBarcodeHistory::factory()
        ->backDatedTo(now()->subDays(5))
        ->create(['box_id' => $box->id]);

    $c = BoxBarcodeHistory::factory()
        ->backDatedTo(now()->subDay())
        ->create(['box_id' => $box->id]);

    $ordered = $box->barcodeHistory()->get();
    expect($ordered->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

// 12 --------------------------------------------------------------------------
test('Box model exposes barcodeHistory() returning a HasMany', function () {
    $box = makeBoxForHistory();
    $rel = $box->barcodeHistory();

    expect($rel)->toBeInstanceOf(HasMany::class);
    expect($rel->getRelated())->toBeInstanceOf(BoxBarcodeHistory::class);
});

// 13 --------------------------------------------------------------------------
test('repository_id on the history row mirrors Box.batch.repository_id', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = makeBoxForHistory(['batch_id' => $batch->id, 'barcode' => 'BC-T-1']);

    $box->update(['barcode' => 'BC-T-2']);

    $row = BoxBarcodeHistory::where('box_id', $box->id)->first();
    expect($row->repository_id)->toBe($repo->id);
});

// 14 --------------------------------------------------------------------------
test('history rows are scoped by RepositoryScope: admin sees all, editor sees own only', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $batchA = Batch::factory()->create(['repository_id' => $repoA->id]);
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);

    $boxA = makeBoxForHistory(['batch_id' => $batchA->id, 'barcode' => 'BC-A-1']);
    $boxB = makeBoxForHistory(['batch_id' => $batchB->id, 'barcode' => 'BC-B-1']);

    $boxA->update(['barcode' => 'BC-A-2']);
    $boxB->update(['barcode' => 'BC-B-2']);

    // Admin: sees both rows.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $allRepos = BoxBarcodeHistory::query()->pluck('repository_id')->unique()->values();
    expect($allRepos->all())->toEqualCanonicalizing([$repoA->id, $repoB->id]);

    // Editor in repoA only: sees only repoA rows.
    auth()->logout();
    $editor = User::factory()->create(['default_repository_id' => $repoA->id]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoA->id);
    $this->actingAs($editor);

    $visible = BoxBarcodeHistory::query()->pluck('repository_id')->unique()->values();
    expect($visible->all())->toBe([$repoA->id]);
});

// 15 --------------------------------------------------------------------------
test('history rows survive a soft-delete of the parent Box', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-SD-1']);
    $box->update(['barcode' => 'BC-SD-2']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(1);

    $box->delete(); // soft delete

    // SoftDeletes preserves the row, so FK is intact and history remains.
    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(1);
});

// 16 --------------------------------------------------------------------------
test('history rows are cascaded away on force-delete', function () {
    $box = makeBoxForHistory(['barcode' => 'BC-FD-1']);
    $box->update(['barcode' => 'BC-FD-2']);

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(1);

    $box->forceDelete();

    expect(BoxBarcodeHistory::where('box_id', $box->id)->count())->toBe(0);
});

// 17 --------------------------------------------------------------------------
test('history row is created with the authenticated user when present', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $user = User::factory()->create(['default_repository_id' => $repo->id]);
    $user->repositories()->attach($repo->id);
    $user->assignRole('editor');
    $this->actingAs($user);

    $box = makeBoxForHistory(['batch_id' => $batch->id, 'barcode' => 'BC-AU-1']);
    $box->update(['barcode' => 'BC-AU-2']);

    $row = BoxBarcodeHistory::where('box_id', $box->id)->first();
    expect($row)->not->toBeNull();
    expect($row->changed_by_user_id)->toBe($user->id);
    expect($row->changedBy)->not->toBeNull();
    expect($row->changedBy->id)->toBe($user->id);
});

// 18 --------------------------------------------------------------------------
test('history row falls back to null user when no auth context', function () {
    auth()->logout();
    $box = makeBoxForHistory(['barcode' => 'BC-NO-AUTH-1']);
    $box->update(['barcode' => 'BC-NO-AUTH-2']);

    $row = BoxBarcodeHistory::where('box_id', $box->id)->first();
    expect($row)->not->toBeNull();
    expect($row->changed_by_user_id)->toBeNull();
});

// 19 --------------------------------------------------------------------------
test('factory produces a valid persistable record', function () {
    $row = BoxBarcodeHistory::factory()->create();

    expect($row->exists)->toBeTrue();
    expect($row->box_id)->toBeInt();
    expect($row->repository_id)->toBeInt();
    expect($row->previous_barcode)->toBeString();
});

// 20 --------------------------------------------------------------------------
test('RelationManager class loads and exposes the configured relationship', function () {
    expect(class_exists(BarcodeHistoryRelationManager::class))->toBeTrue();

    $rm = new ReflectionClass(BarcodeHistoryRelationManager::class);
    $relProp = $rm->getProperty('relationship');
    expect($relProp->getValue())->toBe('barcodeHistory');
});

// 21 --------------------------------------------------------------------------
test('BoxResource registers the BarcodeHistoryRelationManager', function () {
    expect(BoxResource::getRelations())
        ->toContain(BarcodeHistoryRelationManager::class);
});

// 22 --------------------------------------------------------------------------
test('BoxBarcodeHistory::recordChange() helper persists the expected snapshot', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = makeBoxForHistory(['batch_id' => $batch->id, 'barcode' => 'BC-H-1']);

    $row = BoxBarcodeHistory::recordChange(
        box: $box,
        previousBarcode: 'BC-H-1',
        newBarcode: 'BC-H-2',
        previousStatus: 'IN',
        newStatus: 'OUT',
        reason: 'manual back-fill',
    );

    expect($row->box_id)->toBe($box->id);
    expect($row->previous_barcode)->toBe('BC-H-1');
    expect($row->new_barcode)->toBe('BC-H-2');
    expect($row->previous_status)->toBe('IN');
    expect($row->new_status)->toBe('OUT');
    expect($row->reason)->toBe('manual back-fill');
    expect($row->repository_id)->toBe($repo->id);
});
