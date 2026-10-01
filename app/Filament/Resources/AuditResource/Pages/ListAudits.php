<?php

namespace App\Filament\Resources\AuditResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\AuditResource;
use App\Support\History\Timeline;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Collection;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAudits extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = AuditResource::class;

    /**
     * The audit rows the table is showing — its filters and search — one CSV
     * row per changed field (RFQ §3.1.5: old value, new value, user, time).
     * A created / deleted / restored event is one row with no field.
     * Read in chunks: production holds about 91,000 audit rows.
     */
    public function exportToCsv(): StreamedResponse
    {
        abort_unless(AuditResource::canViewAny(), 403);

        $query = $this->getFilteredTableQuery()->with('user:id,name');
        $resolver = Timeline::resolver();

        return response()->streamDownload(function () use ($query, $resolver): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['When', 'Who', 'Event', 'Record type', 'Record', 'Field', 'Old value', 'New value', 'IP address'], escape: '\\');

            $query->chunkById(500, function (Collection $audits) use ($out, $resolver): void {
                foreach ($audits as $audit) {
                    /** @var Audit $audit */
                    $base = [
                        $audit->created_at?->format('Y-m-d H:i:s'),
                        $audit->user?->getAttribute('name') ?? 'system',
                        (string) $audit->event,
                        class_basename((string) $audit->auditable_type),
                        Timeline::recordLabel((string) $audit->auditable_type, $audit->auditable_id, $resolver),
                    ];
                    $changes = Timeline::fieldChanges($audit, $resolver);
                    if ($changes === []) {
                        $changes = [['label' => '', 'from' => null, 'to' => null]];
                    }
                    foreach ($changes as $change) {
                        $row = array_merge($base, [$change['label'], $change['from'], $change['to'], (string) $audit->ip_address]);
                        fputcsv($out, array_map(fn ($v): string => ReportRenderer::sanitizeCsvCell($v), $row), escape: '\\');
                    }
                }
            });

            fclose($out);
        }, 'audit_trail_' . now()->format('Ymd_His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('The changes shown, one row per changed field: when, who, record, field, old value, new value.')
                ->action(fn (): StreamedResponse => $this->exportToCsv()),
        ];
    }
}
