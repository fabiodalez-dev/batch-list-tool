<?php

declare(strict_types=1);

use App\Console\Commands\ImportBatchList;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Schema audit — High findings.
 *
 * (a) Multi-tenant import dedup: an operator with no determinable repository
 *     (a privileged user with no default_repository_id) must NOT match/overwrite
 *     documents by identifier across every tenant. The row is treated as new and
 *     fails closed on the NOT NULL repository_id — never touching another repo.
 * (b) The --truncate-data wipe must include the four ON DELETE CASCADE children.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function itg_repo(string $code): Repository
{
    return Repository::firstOrCreate(['code' => $code], ['name' => $code . ' repo']);
}

function itg_series(): Series
{
    return Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
}

function itg_seedDoc(Repository $repo, string $identifier, string $notes): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => itg_series()->id,
        'repository_id' => $repo->id,
        'notes' => $notes,
    ]);
}

/** @param array<string,string|null> $data */
function itg_runDoc(array $data, User $user): void
{
    test()->actingAs($user);
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $user->id,
    ]);

    // The row may fail closed (NOT NULL repository_id); the security property is
    // that it never mutates another tenant's row, so swallow the failure.
    try {
        (new DocumentImporter($imp, $map, []))($data);
    } catch (Throwable) {
        // fail-closed is acceptable — asserted by the caller on the victim row
    }
}

it('SECURITY: a privileged user with no default repository cannot overwrite another tenant\'s document by identifier', function () {
    $repoA = itg_repo('AAA');
    itg_series();
    $victim = itg_seedDoc($repoA, 'SHARED-1', 'original');

    // super_admin with NO default repository — the exact vulnerable profile.
    $attacker = User::factory()->create(['is_active' => true, 'default_repository_id' => null]);
    $attacker->assignRole('super_admin');

    itg_runDoc([
        'Identifier' => 'SHARED-1',
        'Subseries' => 'REG',
        'Note' => 'HIJACKED',
    ], $attacker);

    // Repo A's document is untouched — no cross-tenant overwrite.
    $victim->refresh();
    expect($victim->notes)->toBe('original')
        ->and($victim->trashed())->toBeFalse();
    // And nothing new landed in repo A either.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $repoA->id)->count())->toBe(1);
});

it('SECURITY: a privileged user with no default cannot RESTORE + overwrite another tenant\'s soft-deleted document', function () {
    $repoA = itg_repo('AAA');
    itg_series();
    $victim = itg_seedDoc($repoA, 'SHARED-2', 'original');
    $victim->delete(); // soft-deleted in repo A

    $attacker = User::factory()->create(['is_active' => true, 'default_repository_id' => null]);
    $attacker->assignRole('super_admin');

    itg_runDoc([
        'Identifier' => 'SHARED-2',
        'Subseries' => 'REG',
        'Note' => 'HIJACKED',
    ], $attacker);

    // Still soft-deleted, still original — not resurrected across tenants.
    $victim->refresh();
    expect($victim->trashed())->toBeTrue()
        ->and($victim->notes)->toBe('original');
});

it('the legitimate within-tenant re-import still updates the operator\'s own document', function () {
    $repoA = itg_repo('AAA');
    itg_series();
    $doc = itg_seedDoc($repoA, 'OWN-1', 'original');

    // super_admin WHOSE default IS repo A — a legitimate operator of A.
    $operator = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoA->id]);
    $operator->assignRole('super_admin');

    itg_runDoc([
        'Identifier' => 'OWN-1',
        'Subseries' => 'REG',
        'Note' => 'updated in place',
    ], $operator);

    // The operator's own document IS updated (dedup still works within the tenant).
    $doc->refresh();
    expect($doc->notes)->toBe('updated in place')
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $repoA->id)->count())->toBe(1);
});

it('the --truncate-data wipe list includes the four ON DELETE CASCADE children', function () {
    $ref = new ReflectionClass(ImportBatchList::class);
    /** @var array<int,string> $tables */
    $tables = $ref->getConstant('DATA_TABLES');

    // Regression guard for the schema-audit fix: TRUNCATE never cascades and
    // resets AUTO_INCREMENT, so these must be wiped alongside their parents.
    expect($tables)->toContain('volumes')
        ->toContain('document_items')
        ->toContain('document_flags')
        ->toContain('box_parents')
        // parents still present too
        ->toContain('documents')
        ->toContain('boxes');
});
