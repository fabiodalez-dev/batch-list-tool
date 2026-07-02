<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Resources\BoxMovementResource;
use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Re-verification gaps (workflow naf-buglog-reverify) — the four items the
 * adversarial re-check judged PARTIAL: #11 first-accession description,
 * #29 richer movement document label, #34 box-number-only picker label,
 * Q8 flag rows with neither identifier nor notary name.
 */
uses(RefreshDatabase::class);

function pit_admin(int $repoId): User
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
function pit_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $columnMap = array_combine(array_keys($data), array_keys($data));
    $importer = new AccessionRowImporter($imp, $columnMap, []);
    $importer($data);
}

/* ─── #29 — movement document label ─────────────────────────────────── */

it('B29: movement label carries identifier, notary full name and volume number', function (): void {
    $doc = qf_doc(['identifier' => 'R100-5', 'volume_number' => '12']);
    $authority = Authority::create([
        'identifier' => 'R-PIT-' . uniqid(),
        'surname' => 'Caruana',
        'given_names' => 'Vincenzo',
        'entity_type' => 'PERSON',
    ]);
    $doc->authorities()->attach($authority->id, ['is_primary' => true]);

    $label = BoxMovementResource::movementDocumentLabel($doc->fresh());

    expect($label)->toContain('R100-5')
        ->and($label)->toContain('Vincenzo Caruana')
        ->and($label)->toContain('vol. 12');
});

it('B29: movement label falls back to dates/notes when the identifier is unknown', function (): void {
    $doc = qf_doc([
        'identifier' => 'AUTO-abc123',
        'catalogue_identifier' => null,
        'object_reference_number' => null,
        'dates' => '1750-1760',
    ]);

    $label = BoxMovementResource::movementDocumentLabel($doc);

    expect($label)->toContain('1750-1760');
});

/* ─── #34 — box-number-only picker label ────────────────────────────── */

it('B34: the short box label shows only the box number, never the batch', function (): void {
    $box = qf_box(['box_number' => '98', 'barcode_status' => 'IN']);

    $short = SearchableSelects::boxShortLabel($box);

    expect($short)->toContain('Box 98')
        ->and($short)->not->toContain('Batch');
});

it('B34: box search results accept a custom short labeler', function (): void {
    $box = qf_box(['box_number' => 'PIT-77']);

    $results = SearchableSelects::boxSearchResults(
        'PIT-77',
        null,
        fn ($r): string => SearchableSelects::boxShortLabel($r),
    );

    expect($results)->toHaveKey($box->id)
        ->and($results[$box->id])->toStartWith('Box PIT-77')
        ->and($results[$box->id])->not->toContain('Batch');
});

/* ─── Q8 — flag rows with neither identifier nor notary name ────────── */

it('Q8: an imported row with neither identifier nor notary name gets a missing_data flag', function (): void {
    $repo = qf_repo();
    $user = pit_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    pit_import([
        'document_identifier' => 'DOC-PIT-Q8',
        'accession_number' => 'ACC-PIT-Q8',
        'accession_title' => 'Q8 accession',
        'batch_number' => 77,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        // NO authority_identifier, NO notary name/surname.
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-PIT-Q8')->first();

    expect($doc)->not->toBeNull();

    $flag = DocumentFlag::query()
        ->where('document_id', $doc->id)
        ->where('type', 'missing_data')
        ->where('status', 'open')
        ->first();

    expect($flag)->not->toBeNull()
        ->and($flag->title)->toContain('No notary');
});

it('Q8: a row WITH an authority identifier gets no missing-notary flag', function (): void {
    $repo = qf_repo();
    $user = pit_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    Authority::create(['identifier' => 'R555', 'surname' => 'Borg', 'entity_type' => 'PERSON']);

    pit_import([
        'document_identifier' => 'DOC-PIT-Q8-OK',
        'accession_number' => 'ACC-PIT-Q8-OK',
        'accession_title' => 'Q8 ok accession',
        'batch_number' => 78,
        'box_number' => '1',
        'document_type' => 'Original',
        'series' => 'REG',
        'authority_identifier' => 'R555',
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-PIT-Q8-OK')->first();

    expect($doc)->not->toBeNull()
        ->and(DocumentFlag::query()->where('document_id', $doc->id)->where('type', 'missing_data')->exists())->toBeFalse();
});
