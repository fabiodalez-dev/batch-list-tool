<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Charlene UX — Document Types list page "Download template" header action.
 *
 * Mirrors tests/Feature/TemplateDownloadTest.php: the generated .xlsx row-1
 * headers must match the Document Types import contract (#17), and the action
 * must be reachable from the list page.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
});

function dttpl_superAdmin(): User
{
    $u = User::factory()->create([
        'email' => 'dttpl-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/** @return array<int, string> */
function dttpl_headers(StreamedResponse $response): array
{
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();

    $dir = storage_path('framework/testing/dttpl');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $path = $dir . '/' . bin2hex(random_bytes(8)) . '.xlsx';
    file_put_contents($path, $bytes);

    $sheet = IOFactory::load($path)->getActiveSheet();
    $headers = [];
    foreach ($sheet->getRowIterator(1, 1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $value = (string) $cell->getValue();
            if ($value !== '') {
                $headers[] = $value;
            }
        }
    }
    // Intentionally don't unlink (mirrors TemplateDownloadTest): the temp file
    // lives in the gitignored storage/framework/testing dir, and an unlink()
    // call here trips semgrep's php.lang.security.unlink-use rule (blocking in CI).

    return $headers;
}

it('generates a documentType template with the expected headers', function () {
    $response = TemplateGenerator::download('documentType');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getStatusCode())->toBe(200);

    expect(dttpl_headers($response))->toBe(['Identifier', 'Name', 'Description', 'Is active']);
});

it('exposes the Download template action on the Document Types list page', function () {
    $this->actingAs(dttpl_superAdmin());

    Livewire::test(ListDocumentTypes::class)
        ->assertOk()
        ->assertActionExists('download_template');
});

it('runs the Download template header action without error', function () {
    $this->actingAs(dttpl_superAdmin());

    Livewire::test(ListDocumentTypes::class)
        ->callAction('download_template')
        ->assertHasNoActionErrors()
        ->assertOk();
});
