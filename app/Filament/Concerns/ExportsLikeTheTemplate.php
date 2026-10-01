<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Repository;
use App\Support\CustomFields\CustomFieldResolver;
use App\Support\Export\EntityExport;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Export CSV" button of a record list: the records the table is showing —
 * its filters, search and repository scope — written in the shape of that
 * record type's import template (see EntityExport).
 *
 * The using page names its template entity in exportEntity().
 */
trait ExportsLikeTheTemplate
{
    public function exportToCsv(): StreamedResponse
    {
        $entity = static::exportEntity();

        // Defence in depth: even if the button is bypassed (a direct method
        // call, a tampered Livewire payload) the method itself refuses.
        abort_unless(auth()->user()?->can('view_any_' . Str::snake($entity)), 403, 'Not authorized to export these records.');

        return EntityExport::stream($entity, $this->exportQuery(), $this->exportFilenameStem($entity));
    }

    abstract protected static function exportEntity(): string;

    /**
     * The records to export: by default exactly what the table is showing.
     *
     * @return Builder<covariant Model>
     */
    protected function exportQuery(): Builder
    {
        return $this->getFilteredTableQuery();
    }

    protected function exportCsvAction(): Action
    {
        $entity = static::exportEntity();

        return Action::make('export_csv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->tooltip('The records shown, in the same columns as the import template — edit and re-import without remapping.')
            ->authorize(fn (): bool => auth()->user()?->can('view_any_' . Str::snake($entity)) ?? false)
            ->action(fn (): StreamedResponse => $this->exportToCsv());
    }

    private function exportFilenameStem(string $entity): string
    {
        $repositoryId = CustomFieldResolver::activeRepositoryId();
        $code = $repositoryId === null ? 'all' : (Repository::query()->whereKey($repositoryId)->value('code') ?? 'all');

        return Str::plural(Str::snake($entity)) . '_' . $code;
    }
}
