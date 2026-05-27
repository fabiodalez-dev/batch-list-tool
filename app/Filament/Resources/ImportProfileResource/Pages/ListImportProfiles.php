<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportProfileResource\Pages;

use App\Filament\Resources\ImportProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListImportProfiles extends ListRecords
{
    protected static string $resource = ImportProfileResource::class;

    /**
     * No "Create" header action: profiles are produced by the Import
     * Wizard's "Save as profile" checkbox on step 5 — that path captures
     * the live column_map state. Authoring one from blank here would
     * produce an empty mapping that can't be reused.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
