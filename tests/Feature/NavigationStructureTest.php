<?php

declare(strict_types=1);

use App\Filament\Resources\AuditResource;
use App\Filament\Resources\BoxMovementResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentTypeResource;
use App\Filament\Resources\ImportProfileResource;
use App\Filament\Resources\Lookups\BoxTypeResource;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource;
use App\Filament\Resources\Lookups\LocationTypeResource;
use App\Filament\Resources\RepositoryResource;

/**
 * Charlene nav restructure (feat/nav-restructure-and-doctype-ux).
 *
 * Locks in the new group assignments, the renamed classification labels and
 * the retirement of the old 'Archive' / 'Reference' / 'Lookups' groups.
 */
it('assigns a representative set of resources to the new groups', function () {
    expect(DocumentResource::getNavigationGroup())->toBe('Records')
        ->and(RepositoryResource::getNavigationGroup())->toBe('Records')
        ->and(DocumentTypeResource::getNavigationGroup())->toBe('Classifications')
        ->and(BoxMovementResource::getNavigationGroup())->toBe('Operations')
        ->and(ImportProfileResource::getNavigationGroup())->toBe('Importation')
        ->and(AuditResource::getNavigationGroup())->toBe('Administration');
});

it('applies the renamed classification labels', function () {
    expect(BoxTypeResource::getNavigationLabel())->toBe('Box Typology')
        ->and(CurrentBoxTypeResource::getNavigationLabel())->toBe('Physical Box Type')
        ->and(LocationTypeResource::getNavigationLabel())->toBe('Location Typology');
});

it('registers the panel navigation groups in the required order', function () {
    $panel = Filament\Facades\Filament::getPanel('admin');

    $groups = collect($panel->getNavigationGroups())
        ->map(fn ($group) => is_string($group) ? $group : $group->getLabel())
        ->values()
        ->all();

    expect($groups)->toBe([
        'Records',
        'Classifications',
        'Operations',
        'Importation',
        'Reports',
        'My Account',
        'Administration',
    ]);
});

it('no resource or page file still references a retired navigation group', function () {
    $retired = ["'Archive'", "'Reference'", "'Lookups'", "'My account'"];

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $offenders = [];
    foreach ($files as $path) {
        $contents = file_get_contents($path);
        foreach ($retired as $needle) {
            // Only flag actual navigationGroup assignments, not column labels
            // such as ->label('Reference').
            if (preg_match('/\$navigationGroup\s*=\s*' . preg_quote($needle, '/') . '/', $contents) === 1) {
                $offenders[] = basename($path) . ' → ' . $needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});
