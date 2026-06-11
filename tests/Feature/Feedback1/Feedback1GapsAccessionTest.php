<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AccessionResource\Pages\CreateAccession;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Models\Accession;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 gaps — AccessionResource (GROUP ACC).
 *
 * Covers:
 *   1 — Attachments on Accessions (client: "Can we have attachments (pdfs)
 *       – multiple – Digriet/Conservation Report/Emails"), mirroring the
 *       Document `attachments` spatie/medialibrary collection: model
 *       implements HasMedia, registers the collection (PDF + image mimes),
 *       form exposes a multiple SpatieMediaLibraryFileUpload, infolist
 *       lists the uploaded files.
 *   2 — Filters always visible: FiltersLayout::AboveContentCollapsible,
 *       mirroring BoxResource.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fga_superAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'fga-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function fga_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'FGA_' . substr(uniqid(), -6),
    ]);
}

function fga_accession(int $repoId, array $attrs = []): Accession
{
    return Accession::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'code' => 'FGA-' . strtoupper(substr(uniqid(), -6)),
        'repository_id' => $repoId,
    ], $attrs));
}

/**
 * A fake upload with REAL minimal PDF bytes. spatie sniffs the mime from the
 * file CONTENT (not the client-supplied mime), so UploadedFile::fake()->create()
 * — whose temp file is empty — sniffs as `application/x-empty` and is rejected
 * by the `attachments` collection allow-list. Magic bytes `%PDF-` make finfo
 * return `application/pdf`.
 */
function fga_fakePdf(string $name): UploadedFile
{
    $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        . "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
        . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

// ===========================================================================
// 1 — Attachments: model level
// ===========================================================================

/**
 * 1.1 — Accession implements the spatie HasMedia contract.
 */
it('Accession model implements HasMedia', function () {
    expect(new Accession)->toBeInstanceOf(HasMedia::class);
});

/**
 * 1.2 — The `attachments` media collection is registered with the same
 * accepted mime list as Document (PDF first — primary client use case —
 * plus JPG/PNG/TIFF/TIF).
 */
it('Accession registers an attachments media collection accepting PDFs', function () {
    $accession = new Accession;
    $accession->registerMediaCollections();

    $collection = collect($accession->mediaCollections)
        ->first(fn ($c) => $c->name === 'attachments');

    expect($collection)->not->toBeNull()
        ->and($collection->acceptsMimeTypes)->toContain('application/pdf')
        ->and($collection->acceptsMimeTypes)->toContain('image/jpeg')
        ->and($collection->acceptsMimeTypes)->toContain('image/png')
        ->and($collection->acceptsMimeTypes)->toContain('image/tiff')
        ->and($collection->acceptsMimeTypes)->toContain('image/tif');
});

/**
 * 1.3 — A fake PDF can be attached to the `attachments` collection and is
 * retrievable via getMedia(); multiple files are supported.
 */
it('attaches multiple fake PDFs to the attachments collection', function () {
    Storage::fake('public');

    $this->actingAs(fga_superAdmin());
    $repo = fga_repo();
    $accession = fga_accession($repo->id);

    $accession
        ->addMedia(fga_fakePdf('digriet.pdf'))
        ->toMediaCollection('attachments');
    $accession
        ->addMedia(fga_fakePdf('conservation-report.pdf'))
        ->toMediaCollection('attachments');

    $media = $accession->refresh()->getMedia('attachments');

    expect($media)->toHaveCount(2)
        ->and($media->pluck('file_name')->all())->toContain('digriet.pdf')
        ->and($media->pluck('file_name')->all())->toContain('conservation-report.pdf')
        ->and($media->first()->collection_name)->toBe('attachments')
        ->and($media->first()->mime_type)->toBe('application/pdf');
});

/**
 * 1.4 — A mime type outside the accepted list is rejected by the collection
 * (mirrors Document: the accepted list is an allow-list, not advisory).
 */
it('rejects a disallowed mime type on the attachments collection', function () {
    Storage::fake('public');

    $this->actingAs(fga_superAdmin());
    $repo = fga_repo();
    $accession = fga_accession($repo->id);

    // Real MZ magic bytes: finfo sniffs `application/x-dosexec`, which is not
    // in the allow-list. (An empty fake would also be rejected, but only as
    // `application/x-empty` — that would not prove executables are blocked.)
    $accession
        ->addMedia(UploadedFile::fake()->createWithContent('malware.exe', "MZ\x90\x00" . str_repeat("\x00", 60)))
        ->toMediaCollection('attachments');
})->throws(FileUnacceptableForCollection::class);

// ===========================================================================
// 1 — Attachments: form + infolist wiring
// ===========================================================================

/**
 * 1.5 — The create form exposes the `attachments` upload field.
 */
it('create form has an attachments upload field', function () {
    $this->actingAs(fga_superAdmin());

    Livewire::test(CreateAccession::class)
        ->assertFormFieldExists('attachments');
});

/**
 * 1.6 — The upload field is a multiple SpatieMediaLibraryFileUpload bound to
 * the `attachments` collection.
 */
it('attachments form field is multiple and bound to the attachments collection', function () {
    $this->actingAs(fga_superAdmin());

    Livewire::test(CreateAccession::class)
        ->assertFormFieldExists('attachments', function ($field): bool {
            return $field instanceof SpatieMediaLibraryFileUpload
                && $field->isMultiple()
                && $field->getCollection() === 'attachments';
        });
});

/**
 * 1.7 — The infolist contains an Attachments entry so uploaded files are
 * listed (and downloadable) on the view page.
 */
it('infolist has an attachments_list entry', function () {
    $this->actingAs(fga_superAdmin());

    $lw = Livewire::test(ListAccessions::class);
    $schema = Schema::make($lw->instance());

    $built = AccessionResource::infolist($schema);

    // Flatten all entries from all top-level Section children.
    $entries = collect();
    foreach ($built->getComponents() as $section) {
        if (method_exists($section, 'getChildComponents')) {
            foreach ($section->getChildComponents() as $entry) {
                $entries->push($entry);
            }
        }
    }

    $entry = $entries->first(fn ($e) => method_exists($e, 'getName') && $e->getName() === 'attachments_list');

    expect($entry)->not->toBeNull();
});

// ===========================================================================
// 2 — Filters always visible (AboveContentCollapsible)
// ===========================================================================

/**
 * 2.1 — The table uses FiltersLayout::AboveContentCollapsible, mirroring
 * BoxResource, so the filter panel is always visible above the content.
 */
it('table uses the AboveContentCollapsible filters layout', function () {
    $this->actingAs(fga_superAdmin());

    $table = AccessionResource::table(
        Table::make(
            Livewire::test(ListAccessions::class)->instance()
        )
    );

    expect($table->getFiltersLayout())->toBe(FiltersLayout::AboveContentCollapsible);
});
