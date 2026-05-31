<?php

namespace App\Filament\Resources\ReportTemplateResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\ReportTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListReportTemplates extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = ReportTemplateResource::class;

    /**
     * No "Create" header action: templates are created from a Report page
     * via the "Save as template" header-action which captures the live
     * filter / column / sort state. Letting users author one from blank
     * here would produce a useless empty template.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
