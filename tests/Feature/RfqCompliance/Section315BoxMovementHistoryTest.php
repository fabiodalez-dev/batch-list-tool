<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.5 — Full audit trail (Old, New, User, Timestamp) for ALL changes,
 * incl. box movement history + barcode change history.
 *
 * Six tests covering:
 *   - BoxMovement records preserve from/to/user/timestamp
 *   - DocumentIdentifierHistory append-only log
 *   - Document->identifierHistory ordering (latest changed_at first)
 *   - DocumentIdentifierHistory::recordChange() factory method
 *   - cascade-on-delete: removing document removes its history
 *   - previousIdentifiers() returns the distinct list
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s315_setup(): array
{
    $repo = Repository::factory()->create(['code' => 'S315-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'S315S-' . substr(uniqid(), -4), 'title' => 'S315', 'is_active' => true]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5500 + random_int(0, 999),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id, 'is_active' => true,
    ]);

    return [$repo, $series, $batch];
}

it('§ 3.1.5 #1: BoxMovement persists from/to/movement_date/user_id quartet', function () {
    [$repo, $series, $batch] = s315_setup();
    $u = User::factory()->create();
    $boxA = Box::factory()->create(['batch_id' => $batch->id]);
    $boxB = Box::factory()->create(['batch_id' => $batch->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'S315A-' . uniqid(),
        'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'current_box_id' => $boxA->id,
    ]);
    $mv = BoxMovement::withoutGlobalScopes()->create([
        'document_id' => $doc->id,
        'from_box_id' => $boxA->id,
        'to_box_id' => $boxB->id,
        'movement_date' => '2026-05-01 10:00:00',
        'user_id' => $u->id,
        'reason' => 'Move',
    ]);
    expect($mv->from_box_id)->toBe($boxA->id)
        ->and($mv->to_box_id)->toBe($boxB->id)
        ->and($mv->user_id)->toBe($u->id)
        ->and($mv->movement_date->format('Y-m-d'))->toBe('2026-05-01');
});

it('§ 3.1.5 #2: DocumentIdentifierHistory append-only log persists previous/new', function () {
    [$repo, $series] = s315_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'NEW-IDENT', 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    $h = DocumentIdentifierHistory::create([
        'document_id' => $doc->id,
        'previous_identifier' => 'OLD-IDENT',
        'new_identifier' => 'NEW-IDENT',
        'changed_at' => now(),
        'repository_id' => $repo->id,
        'reason' => 'Spelling fix',
    ]);
    expect($h->previous_identifier)->toBe('OLD-IDENT')
        ->and($h->new_identifier)->toBe('NEW-IDENT');
});

it('§ 3.1.5 #3: Document->identifierHistory returns history ordered latest changed_at first', function () {
    [$repo, $series] = s315_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CURRENT', 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    DocumentIdentifierHistory::create([
        'document_id' => $doc->id,
        'previous_identifier' => 'OLD-1', 'new_identifier' => 'OLD-2',
        'changed_at' => now()->subDays(2), 'repository_id' => $repo->id,
    ]);
    DocumentIdentifierHistory::create([
        'document_id' => $doc->id,
        'previous_identifier' => 'OLD-2', 'new_identifier' => 'CURRENT',
        'changed_at' => now()->subDay(), 'repository_id' => $repo->id,
    ]);
    $history = $doc->identifierHistory()->get();
    expect($history->count())->toBe(2)
        ->and($history->first()->previous_identifier)->toBe('OLD-2');
});

it('§ 3.1.5 #4: DocumentIdentifierHistory::recordChange() helper creates a row', function () {
    [$repo, $series] = s315_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CR-NEW', 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    $h = DocumentIdentifierHistory::recordChange($doc, 'CR-OLD', 'CR-NEW', 'tested');
    expect($h)->toBeInstanceOf(DocumentIdentifierHistory::class)
        ->and($h->previous_identifier)->toBe('CR-OLD')
        ->and($h->new_identifier)->toBe('CR-NEW')
        ->and($h->reason)->toBe('tested');
});

it('§ 3.1.5 #5: cascade-on-delete — deleting Document hard-deletes its identifier history', function () {
    [$repo, $series] = s315_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'C-DEL', 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    DocumentIdentifierHistory::create([
        'document_id' => $doc->id,
        'previous_identifier' => 'OLD', 'new_identifier' => 'C-DEL',
        'changed_at' => now(), 'repository_id' => $repo->id,
    ]);
    $doc->forceDelete();
    $remaining = DocumentIdentifierHistory::query()->where('document_id', $doc->id)->count();
    expect($remaining)->toBe(0);
});

it('§ 3.1.5 #6: Document->previousIdentifiers() returns the distinct prior id set', function () {
    [$repo, $series] = s315_setup();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CUR-PI', 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    foreach (['A1', 'A2', 'A1'] as $prev) {
        DocumentIdentifierHistory::create([
            'document_id' => $doc->id,
            'previous_identifier' => $prev, 'new_identifier' => 'CUR-PI',
            'changed_at' => now(), 'repository_id' => $repo->id,
        ]);
    }
    $prev = $doc->previousIdentifiers()->all();
    expect(count($prev))->toBe(2);
});
