<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * RFQ Appendix-2 §xi — multi-creator semicolon parsing in the
 * {@see DocumentImporter::class}. The legacy Excel encodes multiple
 * notaries in a SINGLE column delimited by ";":
 *
 *   Identifier column:   "520; 178"                       → R520 + R178
 *   Creator column:      "Calcedonio Gatt; Angelo Cauchi" → matching names
 *
 * These tests pin the contract:
 *  - First non-empty piece becomes is_primary = true;
 *  - All subsequent successful matches attach with is_primary = false;
 *  - Empty / whitespace-only pieces are skipped silently;
 *  - Unknown pieces are skipped but don't poison the row.
 */
uses(RefreshDatabase::class);

function mcr_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function mcr_makeAdmin(int $repoId): User
{
    mcr_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'mcr-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function mcr_repo(string $prefix = 'MCR'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function mcr_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

/**
 * @param array<string, mixed> $data
 */
function mcr_runImporter(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'mcr.xlsx',
        'file_path' => '/tmp/mcr.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    /** @var Importer $importer */
    $importer = new DocumentImporter($row, $columnMap, []);
    $importer($data);
}

test('multi-creator: single identifier attaches exactly one authority as primary', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    $a = Authority::create([
        'identifier' => 'R520',
        'surname' => 'Gatt',
        'entity_type' => 'PERSON',
    ]);

    mcr_runImporter([
        'identifier' => 'DOC-MCR-1',
        'series' => 'REG',
        'authority_identifier' => 'R520',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-1')
        ->firstOrFail();

    $attached = $doc->authorities()->get();
    expect($attached->count())->toBe(1)
        ->and($attached->first()->id)->toBe($a->id)
        ->and((bool) $attached->first()->pivot->is_primary)->toBeTrue();
});

test('multi-creator: "R520; R178" attaches both, first is primary', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    $a1 = Authority::create([
        'identifier' => 'R520',
        'surname' => 'Gatt',
        'entity_type' => 'PERSON',
    ]);
    $a2 = Authority::create([
        'identifier' => 'R178',
        'surname' => 'Cauchi',
        'entity_type' => 'PERSON',
    ]);

    mcr_runImporter([
        'identifier' => 'DOC-MCR-2',
        'series' => 'REG',
        'authority_identifier' => 'R520; R178',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-2')
        ->firstOrFail();

    $attached = $doc->authorities()->get()->keyBy('id');
    expect($attached->count())->toBe(2)
        ->and($attached->has($a1->id))->toBeTrue()
        ->and($attached->has($a2->id))->toBeTrue()
        ->and((bool) $attached->get($a1->id)->pivot->is_primary)->toBeTrue()
        ->and((bool) $attached->get($a2->id)->pivot->is_primary)->toBeFalse();
});

test('multi-creator: empty middle piece "R520; ; R178" is skipped, both still attach', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    $a1 = Authority::create([
        'identifier' => 'R520',
        'surname' => 'Gatt',
        'entity_type' => 'PERSON',
    ]);
    $a2 = Authority::create([
        'identifier' => 'R178',
        'surname' => 'Cauchi',
        'entity_type' => 'PERSON',
    ]);

    mcr_runImporter([
        'identifier' => 'DOC-MCR-3',
        'series' => 'REG',
        'authority_identifier' => 'R520; ; R178',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-3')
        ->firstOrFail();

    $attached = $doc->authorities()->get()->keyBy('id');
    expect($attached->count())->toBe(2)
        ->and($attached->has($a1->id))->toBeTrue()
        ->and($attached->has($a2->id))->toBeTrue()
        ->and((bool) $attached->get($a1->id)->pivot->is_primary)->toBeTrue()
        ->and((bool) $attached->get($a2->id)->pivot->is_primary)->toBeFalse();
});

test('multi-creator: an unknown code "R999; R520" fails the row, naming R999', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    // R999 is intentionally NOT created. Client 2026-10-12: it used to be
    // skipped in silence, so the document was saved without that notary and
    // the row counted as imported.
    Authority::create(['identifier' => 'R520', 'surname' => 'Gatt', 'entity_type' => 'PERSON']);

    expect(fn () => mcr_runImporter([
        'identifier' => 'DOC-MCR-4',
        'series' => 'REG',
        'authority_identifier' => 'R999; R520',
    ], $u->id))->toThrow(ValidationException::class, "Authority Identifier 'R999' matches no authority");
});

test('multi-creator: "Unknown; R520" attaches only R520, as primary', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    $a = Authority::create(['identifier' => 'R520', 'surname' => 'Gatt', 'entity_type' => 'PERSON']);

    mcr_runImporter([
        'identifier' => 'DOC-MCR-4B',
        'series' => 'REG',
        'authority_identifier' => 'Unknown; R520',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-4B')
        ->firstOrFail();

    // Primary is the first authority that RESOLVED; "Unknown" is no notary.
    $attached = $doc->authorities()->get();
    expect($attached->count())->toBe(1)
        ->and($attached->first()->id)->toBe($a->id)
        ->and((bool) $attached->first()->pivot->is_primary)->toBeTrue();
});

test('multi-creator (#9): resolves an authority by its alternative_identifier', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    // Charlene #9: the document links to the notary by the R-code OR the
    // alternate key (here "997"). Both must retrieve the same Authority.
    $a = Authority::create([
        'identifier' => 'R390',
        'alternative_identifier' => '997',
        'surname' => 'Grima',
        'entity_type' => 'PERSON',
    ]);

    mcr_runImporter([
        'identifier' => 'DOC-MCR-ALT',
        'series' => 'REG',
        'authority_identifier' => '997',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-ALT')
        ->firstOrFail();

    $attached = $doc->authorities()->get();
    expect($attached->count())->toBe(1)
        ->and($attached->first()->id)->toBe($a->id)
        ->and((bool) $attached->first()->pivot->is_primary)->toBeTrue();
});

test('multi-creator (#9): a ";"-list mixing R-code and alternate key attaches both', function () {
    $repo = mcr_repo();
    $u = mcr_makeAdmin($repo->id);
    $this->actingAs($u);
    mcr_series('REG');
    $a1 = Authority::create([
        'identifier' => 'R390',
        'alternative_identifier' => '997',
        'surname' => 'Grima',
        'entity_type' => 'PERSON',
    ]);
    $a2 = Authority::create([
        'identifier' => 'R362A',
        'surname' => 'Cauchi',
        'entity_type' => 'PERSON',
    ]);

    // First piece resolves by alternate key, second by R-code.
    mcr_runImporter([
        'identifier' => 'DOC-MCR-MIX',
        'series' => 'REG',
        'authority_identifier' => '997; R362A',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-MCR-MIX')
        ->firstOrFail();

    $attached = $doc->authorities()->get()->keyBy('id');
    expect($attached->count())->toBe(2)
        ->and($attached->has($a1->id))->toBeTrue()
        ->and($attached->has($a2->id))->toBeTrue()
        ->and((bool) $attached->get($a1->id)->pivot->is_primary)->toBeTrue()
        ->and((bool) $attached->get($a2->id)->pivot->is_primary)->toBeFalse();
});
