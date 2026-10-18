<?php

declare(strict_types=1);

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
 * Client 2026-10-18: the Digitised column of her documents sheet holds 167
 * links to the vHMML reading room. Only VHMML / NRA / none were accepted and
 * every link was dropped without a word.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

function dgl_import(string $identifier, string $digitised, int $userId): ?Document
{
    $data = ['Document Identifier' => $identifier, 'Subseries' => 'REG', 'Digitised' => $digitised];
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($imp, ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data)), []))($data);

    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

it('keeps a vHMML link and records the copy as VHMML', function (): void {
    $url = 'https://w3id.org/vhmml/readingRoom/view/514273';
    $doc = dgl_import('DGL-1', $url, $this->user->id);

    expect($doc->digitised)->toBe('VHMML')
        ->and($doc->digitised_url)->toBe($url);
});

it('keeps any other link without guessing where the copy is', function (): void {
    $doc = dgl_import('DGL-2', 'https://example.org/scan/1', $this->user->id);

    expect($doc->digitised)->toBeNull()
        ->and($doc->digitised_url)->toBe('https://example.org/scan/1');
});

it('still takes NRA as written', function (): void {
    $doc = dgl_import('DGL-3', 'nra', $this->user->id);

    expect($doc->digitised)->toBe('NRA')
        ->and($doc->digitised_url)->toBeNull();
});
