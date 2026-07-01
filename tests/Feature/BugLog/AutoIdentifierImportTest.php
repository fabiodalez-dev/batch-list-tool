<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
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
 * Bug-log #22 — a document import row left blank in the identifier column gets an
 * identifier auto-created from Repository / Series / Document Type. Re-importing
 * the same row must NOT duplicate it (deterministic tail), and the Repository
 * code must actually appear (the review found it was resolved too late).
 */
uses(RefreshDatabase::class);

function bug22_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 */
function bug22_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $columnMap = array_combine(array_keys($data), array_keys($data));
    $importer = new DocumentImporter($row, $columnMap, []);
    $importer($data);
}

it('auto-creates an identifier from Repository / Series / Type for a blank row', function (): void {
    $repo = Repository::factory()->create(['code' => 'RX' . substr(uniqid(), -4)]);
    $user = bug22_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    bug22_import([
        'identifier' => '',            // blank → auto-generated
        'series' => 'REG',
        'document_type' => 'Deeds',
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->identifier)->not->toBeEmpty()
        // Repository code (review fix — was silently dropped) + Series + Type slug.
        ->and($doc->identifier)->toContain((string) $repo->code)
        ->and($doc->identifier)->toContain('REG')
        ->and($doc->identifier)->toContain('DEE');
});

it('is idempotent — re-importing the same blank-identifier row does not duplicate', function (): void {
    $repo = Repository::factory()->create(['code' => 'RY' . substr(uniqid(), -4)]);
    $user = bug22_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    $row = [
        'identifier' => '',
        'series' => 'REG',
        'document_type' => 'Deeds',
        'catalogue_identifier' => 'CAT-IDEMP-1',
    ];

    bug22_import($row, $user->id);
    $first = Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();

    bug22_import($row, $user->id);

    // Same deterministic identifier → resolveRecord matched + updated, no dup.
    expect(Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', $first->identifier)->count())->toBe(1);
});
