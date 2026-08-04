<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Slice 1 — Tracking Note (client feedback 2026-08-04). The document already
 * had a `tracking` column (col 47 of the client's batch list); boxes now have a
 * dedicated `tracking_note`. These tests drive the real importer entry point
 * (the same `($data)` invocation the streaming job uses) through
 * ImportWizard::guessColumnMap, so they exercise the real column resolution.
 */
uses(RefreshDatabase::class);

function tni_actAsAdmin(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    return [$repo, $u];
}

/** @param array<string,string|null> $data */
function tni_runImporter(string $importer, array $data, int $userId): void
{
    $map = ImportWizard::guessColumnMap($importer, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new $importer($imp, $map, []))($data);
}

it('imports a box Tracking Note into boxes.tracking_note', function () {
    [$repo, $u] = tni_actAsAdmin();
    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => '1', 'repository_id' => $repo->id],
    );

    tni_runImporter(BoxImporter::class, [
        'box_type' => 'RAS',
        'box_number' => 'TN-1',
        'batch_number' => '1',
        'barcode' => 'BC-TN-1',
        'Tracking Note' => 'moved to shelf B on 2026-08-01',
    ], $u->id);

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'TN-1')->first();

    expect($box)->not->toBeNull()
        ->and($box->tracking_note)->toBe('moved to shelf B on 2026-08-01')
        ->and($box->notes)->toBeNull();
});

it('imports a document Tracking Note into documents.tracking (new header)', function () {
    [$repo, $u] = tni_actAsAdmin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);

    tni_runImporter(DocumentImporter::class, [
        'Identifier' => 'DOC-TN-1',
        'Series' => 'REG',
        'Tracking Note' => 'tracking via new header',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-TN-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->tracking)->toBe('tracking via new header');
});

it('still imports the legacy "Tracking" header into documents.tracking (backward compat)', function () {
    [$repo, $u] = tni_actAsAdmin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);

    tni_runImporter(DocumentImporter::class, [
        'Identifier' => 'DOC-TN-2',
        'Series' => 'REG',
        'Tracking' => 'tracking via legacy header',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-TN-2')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->tracking)->toBe('tracking via legacy header');
});
