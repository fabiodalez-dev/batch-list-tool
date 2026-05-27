<?php

namespace App\Filament\Resources\ReportTemplateResource\Pages;

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Resources\ReportTemplateResource;
use App\Models\ReportTemplate;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewReportTemplate extends ViewRecord
{
    protected static string $resource = ReportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_report')
                ->label('Open report')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (): ?string => $this->buildReportUrl()),

            Actions\EditAction::make()
                ->visible(fn (): bool => ReportTemplateResource::canManage($this->getRecord())),

            Actions\DeleteAction::make()
                ->visible(fn (): bool => ReportTemplateResource::canManage($this->getRecord())),
        ];
    }

    /**
     * Build a deep link into the target report page, packing the saved
     * filter/columns/sort state into the query string so the report's
     * mount() can restore it.
     */
    protected function buildReportUrl(): ?string
    {
        /** @var ReportTemplate $tpl */
        $tpl = $this->getRecord();

        $page = match ($tpl->source) {
            ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH => DocumentsByBatchReport::class,
            ReportTemplate::SOURCE_DOCUMENTS_BY_CREATOR => DocumentsByCreatorReport::class,
            ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES => DocumentsBySeriesReport::class,
            ReportTemplate::SOURCE_PENDING_DISINFESTATION => PendingDisinfestationReport::class,
            ReportTemplate::SOURCE_BOX_MOVEMENTS => BoxMovementHistoryReport::class,
            ReportTemplate::SOURCE_FLAGS_BY_TYPE => FlagsByTypeReport::class,
            default => null,
        };

        if ($page === null) {
            return null;
        }

        return $page::getUrl(['template' => $tpl->getKey()]);
    }
}
