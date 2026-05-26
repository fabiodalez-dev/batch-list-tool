<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.12 — Replace spreadsheet colour-coding with structured issue flags
 * that can be searched, filtered, reported on, and resolved.
 *
 * Eight tests pinning the structured-flag contract: 10 types × 3 severities
 * × 4 statuses, workflow methods, open/closed scopes, multi-tenant
 * mirroring of repository_id, audit on resolution.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s3112_makeDoc(): Document
{
    $repo = Repository::factory()->create(['code' => 'S3112-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'F-' . substr(uniqid(), -4), 'title' => 'F', 'is_active' => true]);

    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DF-' . uniqid(),
        'document_type' => 'R',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
}

it('§ 3.1.12 #1: DocumentFlag::TYPES lists all 10 RFQ-supported flag categories', function () {
    expect(count(DocumentFlag::TYPES))->toBe(10);
    foreach (['needs_review', 'missing_data', 'duplicate_suspect', 'damaged',
        'restoration_needed', 'wrongly_catalogued', 'authority_mismatch',
        'barcode_issue', 'disinfestation_overdue', 'other'] as $t) {
        expect(in_array($t, DocumentFlag::TYPES, true))->toBeTrue("Type {$t} should be in TYPES");
    }
});

it('§ 3.1.12 #2: DocumentFlag::SEVERITIES lists exactly [info, warning, critical]', function () {
    expect(DocumentFlag::SEVERITIES)->toBe(['info', 'warning', 'critical']);
});

it('§ 3.1.12 #3: DocumentFlag::STATUSES lists exactly 4 workflow positions', function () {
    expect(DocumentFlag::STATUSES)->toBe(['open', 'acknowledged', 'resolved', 'dismissed']);
});

it('§ 3.1.12 #4: creating a flag on a Document mirrors repository_id (tenant)', function () {
    $doc = s3112_makeDoc();
    $flag = DocumentFlag::create([
        'document_id' => $doc->id,
        'type' => 'needs_review',
        'title' => 'Test flag',
    ]);
    expect($flag->repository_id)->toBe($doc->repository_id);
});

it('§ 3.1.12 #5: markResolved() transitions open → resolved with notes + user', function () {
    $doc = s3112_makeDoc();
    $u = User::factory()->create();
    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $flag->markResolved($u, 'Fixed.');
    expect($flag->fresh()->status)->toBe('resolved')
        ->and($flag->fresh()->resolution_notes)->toBe('Fixed.')
        ->and($flag->fresh()->resolved_by_user_id)->toBe($u->id);
});

it('§ 3.1.12 #6: markDismissed() transitions open → dismissed (false positive)', function () {
    $doc = s3112_makeDoc();
    $u = User::factory()->create();
    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $flag->markDismissed($u, 'Not actionable.');
    expect($flag->fresh()->status)->toBe('dismissed');
});

it('§ 3.1.12 #7: markAcknowledged() transitions open → acknowledged (still open)', function () {
    $doc = s3112_makeDoc();
    $flag = DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    $flag->markAcknowledged();
    expect($flag->fresh()->status)->toBe('acknowledged')
        ->and($flag->fresh()->isOpen())->toBeTrue();
});

it('§ 3.1.12 #8: scope open() returns open + acknowledged; closed() returns resolved + dismissed', function () {
    $doc = s3112_makeDoc();
    DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'open']);
    DocumentFlag::factory()->create(['document_id' => $doc->id, 'status' => 'acknowledged']);
    DocumentFlag::factory()->resolved()->create(['document_id' => $doc->id]);
    DocumentFlag::factory()->dismissed()->create(['document_id' => $doc->id]);

    $openCount = DocumentFlag::withoutGlobalScope(RepositoryScope::class)
        ->where('document_id', $doc->id)->open()->count();
    $closedCount = DocumentFlag::withoutGlobalScope(RepositoryScope::class)
        ->where('document_id', $doc->id)->closed()->count();
    expect($openCount)->toBe(2)->and($closedCount)->toBe(2);
});
