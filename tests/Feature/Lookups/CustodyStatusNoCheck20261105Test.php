<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Lookup\CustodyStatusType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Client 2026-11-05: a status added on the Custody Status Types page must fit in
 * documents.custody_status. The CHECK that once listed the allowed values exists
 * only on MySQL/MariaDB, so this also runs against the real driver in the CI's
 * constraint step.
 */
uses(RefreshDatabase::class);

it('stores a status of the page: no CHECK left on the column', function (): void {
    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $doc = Document::factory()->create();

    DB::table('documents')->where('id', $doc->id)->update(['custody_status' => 'on_loan']);
    expect(DB::table('documents')->where('id', $doc->id)->value('custody_status'))->toBe('on_loan');

    $saved = Document::factory()->create(['custody_status' => 'on_loan']);
    expect($saved->fresh()->custody_status)->toBe('on_loan');
});
