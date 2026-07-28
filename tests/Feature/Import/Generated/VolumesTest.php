<?php

declare(strict_types=1);

use App\Filament\Imports\VolumeImporter;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Models\Volume;
use App\Support\BulkImport\EntityResolver;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * VolumeImporter — bug hunt over the REAL streaming path (ImportExcel, the
 * job the "Import Excel/CSV" button on the Volumes resource dispatches).
 *
 * Real input: nra/outbox/2026-07-22_NAF_import_examples/example_volume_import.xlsx
 * has headers document_identifier, volume_number, dates_start, dates_end, notes
 * — identical to VolumeImporter's static column names, so the column map
 * below is the identity map (mirrors how the wizard would resolve it).
 *
 * FOCUS: volume fields; dependencies (parent Document resolution); numeric
 * coercion; dirty-state restore (soft-delete residue); missing-required
 * errors surfacing clearly instead of the masked generic_validation.
 */
uses(RefreshDatabase::class);

/* ─── Shared helpers (namespaced "vt_" to avoid collisions with sibling
   Generated/* test files when the whole suite runs together) ────────────── */

function vt_roles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function vt_user(?int $repoId = null): User
{
    bl_seedShieldPermissions();
    vt_roles();
    $user = User::factory()->create([
        'email' => 'vt-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $user->assignRole('super_admin');
    if ($repoId !== null) {
        $user->repositories()->syncWithoutDetaching([$repoId => ['is_default' => true]]);
        $user->refresh();
    }

    return $user;
}

function vt_repo(string $prefix = 'VOLT'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function vt_series(string $prefix = 'VT'): Series
{
    return Series::create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
        'title' => 'Volumes test series',
        'is_active' => true,
    ]);
}

function vt_document(int $repoId, int $seriesId, string $identifier): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ]);
}

/**
 * Run the REAL hayderhatem ImportExcel job (the streaming path the client's
 * "Import Excel/CSV" button uses) for the given rows.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function vt_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    CustomFieldResolver::flush();

    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'volumes.xlsx',
        'file_path' => '/tmp/volumes.xlsx',
        'importer' => VolumeImporter::class,
        'processed_rows' => 0,
        'total_rows' => count($rows),
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $job = new ImportExcel(
        importId: $import->getKey(),
        rows: base64_encode(serialize($rows)),
        startRow: null,
        endRow: null,
        columnMap: $columnMap,
        options: $options,
    );
    $job->handle();

    return $import->refresh();
}

/** @return array<int, string> */
function vt_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/** The identity column map matching the real sample file's headers. */
const VT_MAP = [
    'document_identifier' => 'document_identifier',
    'volume_number' => 'volume_number',
    'dates_start' => 'dates_start',
    'dates_end' => 'dates_end',
    'notes' => 'notes',
];

// ─── 1. Real sample rows import cleanly end to end ────────────────────────

test('the real sample file rows (two volumes of R642/001) import cleanly via the streaming path', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'R642/001');

    $import = vt_run([
        ['document_identifier' => 'R642/001', 'volume_number' => '1', 'dates_start' => '1870-01-01', 'dates_end' => '1870-12-31', 'notes' => ''],
        ['document_identifier' => 'R642/001', 'volume_number' => '2', 'dates_start' => '1871-01-01', 'dates_end' => '1871-12-31', 'notes' => 'Second volume of the same register'],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::query()->count())->toBe(2);
    $v2 = Volume::query()->where('volume_number', '2')->first();
    expect($v2)->not->toBeNull()
        ->and($v2->notes)->toBe('Second volume of the same register')
        ->and($v2->dates_start?->format('Y-m-d'))->toBe('1871-01-01')
        ->and($v2->dates_end?->format('Y-m-d'))->toBe('1871-12-31');
});

// ─── 2. Numeric volume_number cell (int from Excel) coerces to string ─────

test('an integer volume_number cell (Excel numeric column) imports as its string form', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'NUM-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'NUM-DOC-1', 'volume_number' => 3, 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::query()->where('volume_number', '3')->exists())->toBeTrue();
});

// ─── 3. Numeric document_identifier resolves via string coercion ──────────

test('a numeric document_identifier cell resolves the parent document (numeric identifiers exist in real data)', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, '9001');

    $import = vt_run([
        ['document_identifier' => 9001, 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::query()->where('document_id', $doc->id)->exists())->toBeTrue();
});

// ─── 4. Dirty DB: soft-deleted volume restored on re-import ───────────────

test('streaming re-import of a soft-deleted volume (document_id, volume_number) restores it, not duplicated', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'SD-DOC-1');

    Volume::create(['document_id' => $doc->id, 'volume_number' => '1', 'notes' => 'old'])->delete();
    expect(Volume::count())->toBe(0)
        ->and(Volume::withTrashed()->count())->toBe(1);

    $import = vt_run([
        ['document_identifier' => 'SD-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => 'restored'],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::count())->toBe(1)
        ->and(Volume::withTrashed()->count())->toBe(1)
        ->and(Volume::first()->notes)->toBe('restored');
});

// ─── 5. Dirty DB: mix of live, soft-deleted, and new volumes in one file ──

test('a mix of live, soft-deleted and new volumes for the same document import correctly in one file', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'MIX-DOC-1');

    Volume::create(['document_id' => $doc->id, 'volume_number' => 'LIVE', 'notes' => 'already here']);
    Volume::create(['document_id' => $doc->id, 'volume_number' => 'GONE', 'notes' => 'deleted'])->delete();

    $import = vt_run([
        ['document_identifier' => 'MIX-DOC-1', 'volume_number' => 'LIVE', 'dates_start' => null, 'dates_end' => null, 'notes' => 'updated'],
        ['document_identifier' => 'MIX-DOC-1', 'volume_number' => 'GONE', 'dates_start' => null, 'dates_end' => null, 'notes' => 'restored'],
        ['document_identifier' => 'MIX-DOC-1', 'volume_number' => 'NEW', 'dates_start' => null, 'dates_end' => null, 'notes' => 'fresh'],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::where('document_id', $doc->id)->count())->toBe(3)
        ->and(Volume::withTrashed()->where('document_id', $doc->id)->count())->toBe(3)
        ->and(Volume::where('volume_number', 'LIVE')->value('notes'))->toBe('updated')
        ->and(Volume::where('volume_number', 'GONE')->value('notes'))->toBe('restored');
});

// ─── 6. Missing document_identifier fails validation clearly ──────────────

test('a row missing document_identifier fails with a clear required-field message, not generic_validation', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);

    $import = vt_run([
        ['document_identifier' => '', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('document_identifier');
    expect(Volume::query()->count())->toBe(0);
});

// ─── 7. Unknown document_identifier fails with a clear message ────────────

test('an unresolvable document_identifier fails with a clear message, not generic_validation', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);

    $import = vt_run([
        ['document_identifier' => 'NO-SUCH-DOC-XYZ', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE')
        ->and(strtolower($failures[0]))->toContain('no document found');
    expect(Volume::query()->count())->toBe(0);
});

// ─── 8. Cross-repository document is not resolved (tenant isolation) ──────

test('a document belonging to another repository is not resolved — no cross-tenant volume creation', function () {
    $repoA = vt_repo('VTA');
    $repoB = vt_repo('VTB');
    $userA = vt_user($repoA->id);
    $this->actingAs($userA);
    $series = vt_series();
    vt_document($repoB->id, $series->id, 'XTEN-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'XTEN-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $userA->id);

    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('no document found');
    expect(Volume::query()->count())->toBe(0);
});

// ─── 9. skip_duplicates surfaces a clear skip, not a generic error ────────

test('skip_duplicates against an existing (document, volume_number) surfaces a clear skip message', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'SKIP-DOC-1');
    Volume::create(['document_id' => $doc->id, 'volume_number' => '1', 'notes' => 'here']);

    $import = vt_run([
        ['document_identifier' => 'SKIP-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => 'again'],
    ], VT_MAP, $u->id, ['skip_duplicates' => true]);

    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('skip');
    expect(Volume::where('document_id', $doc->id)->where('volume_number', '1')->value('notes'))->toBe('here');
});

// ─── 10. Duplicate rows for the same key within one file: last wins ───────

test('duplicate rows for the same (document, volume_number) within one file do not crash or duplicate', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'DUPFILE-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'DUPFILE-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => 'first'],
        ['document_identifier' => 'DUPFILE-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => 'second'],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::where('document_id', $doc->id)->where('volume_number', '1')->count())->toBe(1)
        ->and(Volume::where('document_id', $doc->id)->where('volume_number', '1')->value('notes'))->toBe('second');
});

// ─── 11. European day-first dates parse correctly ─────────────────────────

test('a European day-first date (31/05/2023) parses correctly for dates_start', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'EUDATE-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'EUDATE-DOC-1', 'volume_number' => '1', 'dates_start' => '31/05/2023', 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    $v = Volume::where('document_id', Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'EUDATE-DOC-1')->first()->id)->first();
    expect($v->dates_start?->format('Y-m-d'))->toBe('2023-05-31');
});

// ─── 12. Garbage date text does not fail the row (parses to null) ─────────

test('an unparseable date string does not fail the row — it is stored as null', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'BADDATE-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'BADDATE-DOC-1', 'volume_number' => '1', 'dates_start' => 'not a date at all !!', 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    $v = Volume::where('document_id', $doc->id)->first();
    expect($v)->not->toBeNull()
        ->and($v->dates_start)->toBeNull();
});

// ─── 13. Custom field for volume persists via the streaming path ──────────

test('a volume custom field (by label header) persists via EAV over the streaming path', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'CF-DOC-1');

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'volume',
        'key' => 'condition',
        'label' => 'Condition',
        'type' => 'text',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $map = VT_MAP + ['custom_field_condition' => 'Condition'];
    $import = vt_run([
        ['document_identifier' => 'CF-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null, 'Condition' => 'Fragile'],
    ], $map, $u->id);

    expect(vt_failures($import))->toBe([]);
    $v = Volume::where('document_id', $doc->id)->first();
    $defId = CustomFieldDefinition::query()
        ->where('repository_id', $repo->id)
        ->where('entity_type', 'volume')
        ->where('key', 'condition')
        ->value('id');
    $value = CustomFieldValue::query()
        ->where('customizable_id', $v->id)
        ->where('customizable_type', Volume::class)
        ->where('custom_field_definition_id', $defId)
        ->value('value');
    expect($value)->toBe('Fragile');
});

// ─── 14. BUG: blank volume_number always fails despite a "nullable" rule ──

test('BUG: a blank volume_number cell fails the row at the DB layer even though the importer rule says nullable', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'BLANKVOL-DOC-1');

    // A genuinely blank cell arrives as null from PhpSpreadsheet (setReadEmptyCells
    // false, getValue() on an empty cell) — exactly like the real streaming path.
    $import = vt_run([
        ['document_identifier' => 'BLANKVOL-DOC-1', 'volume_number' => null, 'dates_start' => null, 'dates_end' => null, 'notes' => 'no volume number given'],
    ], VT_MAP, $u->id);

    // volume_number's ImportColumn rule is ['nullable', 'string', 'max:64'] — the
    // importer's OWN validation contract says this is optional. But the volumes
    // table column is NOT NULL (no ->nullable() in the migration), so the row
    // always fails at save() instead of never having been allowed to look
    // "optional" in the first place.
    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('volume_number');
    expect(Volume::query()->count())->toBe(0);
});

// ─── 15. Whitespace-padded volume_number still re-imports idempotently ────
// (Hypothesis going in: resolveRecord()'s peek trims but the eventual saved
// value might not, breaking the lookup on re-import. VERIFIED FALSE — Filament's
// own ImportColumn::castStateItem() trims every string cell during castData(),
// which runs BEFORE resolveRecord(), so the stored value is already trimmed
// and re-import correctly matches. Kept as a regression guard, not a bug.)

test('a volume_number with surrounding whitespace still re-imports idempotently (no duplicate)', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'WSVOL-DOC-1');

    $row = ['document_identifier' => 'WSVOL-DOC-1', 'volume_number' => ' 1 ', 'dates_start' => null, 'dates_end' => null, 'notes' => 'run'];

    $import1 = vt_run([$row], VT_MAP, $u->id);
    expect(vt_failures($import1))->toBe([]);
    expect(Volume::where('document_id', $doc->id)->count())->toBe(1);

    // Re-import the EXACT same row again (the operator re-uploading the same
    // file, or a scheduled re-sync) — idempotent importers must UPDATE the
    // existing row, not create a second one.
    $import2 = vt_run([$row], VT_MAP, $u->id);
    expect(vt_failures($import2))->toBe([]);

    expect(Volume::where('document_id', $doc->id)->count())->toBe(1);
});

// ─── 16. Very long notes value does not fail (text column) ────────────────

test('a very long notes value imports without failure (text column has no practical limit)', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    $doc = vt_document($repo->id, $series->id, 'LONGNOTE-DOC-1');

    // Filament's default ImportColumn::castStateItem() trims every string cell
    // (see vendor/filament/actions/src/Imports/ImportColumn.php) — so the
    // trailing space from str_repeat's last unit is expected to disappear.
    $longNote = trim(str_repeat('Provenance detail. ', 500)); // ~9500 chars

    $import = vt_run([
        ['document_identifier' => 'LONGNOTE-DOC-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => $longNote . ' '],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::where('document_id', $doc->id)->value('notes'))->toBe($longNote);
});

// ─── 17. Float Excel cell for volume_number imports as its string form ────

test('a float volume_number cell (e.g. 2.5) imports as its literal string form', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'FLOATVOL-DOC-1');

    $import = vt_run([
        ['document_identifier' => 'FLOATVOL-DOC-1', 'volume_number' => 2.5, 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    expect(vt_failures($import))->toBe([]);
    expect(Volume::query()->where('volume_number', '2.5')->exists())->toBeTrue();
});

// ─── 18. Case-sensitive document_identifier does not match a different case ─

test('a differently-cased document_identifier does not resolve the document (case-sensitive lookup)', function () {
    $repo = vt_repo();
    $u = vt_user($repo->id);
    $this->actingAs($u);
    $series = vt_series();
    vt_document($repo->id, $series->id, 'CASE-Doc-1');

    $import = vt_run([
        ['document_identifier' => 'case-doc-1', 'volume_number' => '1', 'dates_start' => null, 'dates_end' => null, 'notes' => null],
    ], VT_MAP, $u->id);

    $failures = vt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('no document found');
    expect(Volume::query()->count())->toBe(0);
});
