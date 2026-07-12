<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Mass-upload walkthrough (client request 2026-07-07) — pins the exact
 * behaviour I demo to NAF: what the New Accession import AUTO-CREATES
 * (Accession, Batch, Box, Document, and even the Authority when its
 * identifier is new) and what must EXIST BEFOREHAND (the Series; the
 * Repository comes from the importing user's default).
 */
uses(RefreshDatabase::class);

function mw_admin(int $repoId): User
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
function mw_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'walkthrough.xlsx',
        'file_path' => '/tmp/walkthrough.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $columnMap = array_combine(array_keys($data), array_keys($data));
    (new AccessionRowImporter($imp, $columnMap, []))($data);
}

it('walkthrough: one complete row auto-creates accession, batch, box and document, all linked', function (): void {
    $repo = qf_repo();
    $user = mw_admin($repo->id);
    $this->actingAs($user);

    // PREREQUISITE: the Series must exist before the upload.
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    mw_import([
        'document_identifier' => 'WT-DOC-1',
        'accession_number' => 'ACC-WT-1',
        'accession_title' => 'Walkthrough accession',
        'batch_number' => 61,
        'box_number' => '5',
        'box_barcode' => 'WT-BC-0001',
        'document_type' => 'Register',
        'series' => 'REG',
        'authority_identifier' => 'R777',
        'authority_name' => 'Vincenzo',
        'authority_surname' => 'Caruana',
    ], $user->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'WT-DOC-1')->first();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 61)->first();
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', '5')->first();
    $accession = Accession::withoutGlobalScopes()->where('accession_number', 'ACC-WT-1')->first();
    $authority = Authority::withoutGlobalScopes()->where('identifier', 'R777')->first();

    // Everything on the row was created and wired together.
    expect($doc)->not->toBeNull()
        ->and($batch)->not->toBeNull()
        ->and($box)->not->toBeNull()
        ->and($accession)->not->toBeNull()
        ->and($authority)->not->toBeNull()          // authority auto-created too
        ->and($authority->surname)->toBe('Caruana')
        ->and($doc->batch_id)->toBe($batch->id)
        ->and($doc->current_box_id)->toBe($box->id)
        ->and($doc->repository_id)->toBe($repo->id) // from the importing user's default
        ->and($box->batch_id)->toBe($batch->id)
        ->and($box->barcode_status)->toBe('IN')     // boxes go straight to RAS (Q7)
        ->and($doc->authorities()->pluck('authorities.id')->all())->toContain($authority->id)
        ->and($batch->accessions()->pluck('accessions.id')->all())->toContain($accession->id);
});

it('walkthrough: the Series is the one prerequisite — an unknown code is a clear row error', function (): void {
    $repo = qf_repo();
    $user = mw_admin($repo->id);
    $this->actingAs($user);
    // No Series created on purpose.

    expect(fn () => mw_import([
        'document_identifier' => 'WT-DOC-NOSERIES',
        'accession_number' => 'ACC-WT-2',
        'accession_title' => 'No series accession',
        'batch_number' => 62,
        'box_number' => '1',
        'document_type' => 'Register',
        'series' => 'NOPE',
        'authority_identifier' => 'R778',
    ], $user->id))->toThrow(ValidationException::class, 'not found');
});

it('walkthrough: an existing authority is matched by identifier and a name mismatch is rejected', function (): void {
    $repo = qf_repo();
    $user = mw_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    Authority::create(['identifier' => 'R779', 'surname' => 'Borg', 'entity_type' => 'PERSON']);

    expect(fn () => mw_import([
        'document_identifier' => 'WT-DOC-MISMATCH',
        'accession_number' => 'ACC-WT-3',
        'accession_title' => 'Mismatch accession',
        'batch_number' => 63,
        'box_number' => '1',
        'document_type' => 'Register',
        'series' => 'REG',
        'authority_identifier' => 'R779',
        'authority_surname' => 'Caruana', // wrong on purpose — R779 is Borg
    ], $user->id))->toThrow(ValidationException::class, 'does not match');
});

it('walkthrough: re-uploading the same sheet updates the existing records instead of duplicating', function (): void {
    $repo = qf_repo();
    $user = mw_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    $row = [
        'document_identifier' => 'WT-DOC-IDEMP',
        'accession_number' => 'ACC-WT-4',
        'accession_title' => 'Idempotent accession',
        'batch_number' => 64,
        'box_number' => '1',
        'document_type' => 'Register',
        'series' => 'REG',
        'authority_identifier' => 'R780',
    ];

    mw_import($row, $user->id);
    mw_import($row, $user->id);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'WT-DOC-IDEMP')->count())->toBe(1)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 64)->count())->toBe(1)
        ->and(Accession::withoutGlobalScopes()->where('accession_number', 'ACC-WT-4')->count())->toBe(1)
        ->and(Authority::withoutGlobalScopes()->where('identifier', 'R780')->count())->toBe(1);
});

it('walkthrough: the forbidden batch numbers are refused with a clear message', function (): void {
    $repo = qf_repo();
    $user = mw_admin($repo->id);
    $this->actingAs($user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);

    // RFQ Appendix-1 #1 — batches 34/36 are forbidden for imports.
    expect(fn () => mw_import([
        'document_identifier' => 'WT-DOC-FORBIDDEN',
        'accession_number' => 'ACC-WT-5',
        'accession_title' => 'Forbidden batch accession',
        'batch_number' => 34,
        'box_number' => '1',
        'document_type' => 'Register',
        'series' => 'REG',
        'authority_identifier' => 'R781',
    ], $user->id))->toThrow(ValidationException::class);
});
