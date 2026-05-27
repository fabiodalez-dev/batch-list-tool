<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports\Concerns;

use App\Models\ReportTemplate;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;

/**
 * Save-as-template + load-from-template helpers for canned report pages
 * (RFQ §3.2.2).
 *
 * Each consuming Report Page must define a `REPORT_SOURCE` const matching
 * one of {@see ReportTemplate::SOURCES} — the trait pulls it via
 * `static::REPORT_SOURCE` to wire the template's `source` column.
 *
 * The trait provides:
 *   - {@see saveAsTemplateAction()} → header Action that captures the
 *     current `$tableFilters` / `$tableSort` state into a new
 *     ReportTemplate row;
 *   - {@see applyTemplateFromQuery()} → call from `mount()` to restore
 *     state when the URL carries `?template=N`.
 *
 * @phpstan-require-extends Page
 *
 * @phpstan-require-implements HasTable
 */
trait HasReportTemplates
{
    /**
     * Build the "Save as template" header action — call it from
     * {@see getHeaderActions()} on each Report Page.
     */
    protected function saveAsTemplateAction(): Action
    {
        return Action::make('save_as_template')
            ->label('Save as template')
            ->icon('heroicon-o-bookmark')
            ->color('gray')
            ->form([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(191),
                Forms\Components\Textarea::make('description')
                    ->maxLength(500)
                    ->rows(2),
                Forms\Components\Toggle::make('is_shared')
                    ->label('Share with my repository')
                    ->helperText('Other users in your repository will see this template.')
                    ->inline(false),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                if ($user === null) {
                    return; // canAccess() already gates the page; defensive
                }

                $sortPayload = null;
                /** @var string|null $tableSort */
                $tableSort = $this->tableSort ?? null;
                if (is_string($tableSort) && $tableSort !== '') {
                    [$col, $dir] = array_pad(explode(':', $tableSort, 2), 2, 'asc');
                    $sortPayload = ['column' => $col, 'direction' => $dir];
                }

                ReportTemplate::create([
                    'user_id' => $user->getKey(),
                    'repository_id' => $user->getAttribute('default_repository_id'),
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'source' => static::REPORT_SOURCE,
                    'filters' => $this->tableFilters ?? [],
                    'columns' => method_exists($this, 'getActiveColumns')
                        ? $this->getActiveColumns()
                        : null,
                    'sort' => $sortPayload,
                    'is_shared' => (bool) ($data['is_shared'] ?? false),
                ]);

                Notification::make()
                    ->title("Template '{$data['name']}' saved")
                    ->success()
                    ->send();
            });
    }

    /**
     * Restore filter / sort state from a `?template=N` query-string
     * parameter. Call this from `mount()` AFTER the parent's
     * {@see Page::mount()} has run, so the table state
     * properties have been initialised.
     */
    protected function applyTemplateFromQuery(): void
    {
        $id = request()->query('template');
        if ($id === null) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        /** @var ReportTemplate|null $tpl */
        $tpl = ReportTemplate::query()
            ->accessibleBy($user)
            ->where('source', static::REPORT_SOURCE)
            ->whereKey($id)
            ->first();

        if ($tpl === null) {
            return;
        }

        $filters = $tpl->filters;
        if (is_array($filters)) {
            $this->tableFilters = $filters;
        }

        $sort = $tpl->sort;
        if (is_array($sort) && ! empty($sort['column'])) {
            $dir = isset($sort['direction']) && in_array($sort['direction'], ['asc', 'desc'], true)
                ? $sort['direction']
                : 'asc';
            $this->tableSort = $sort['column'] . ':' . $dir;
        }
    }
}
