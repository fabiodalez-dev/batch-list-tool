<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Location;
use App\Models\Repository;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.9 — Bulk import for the {@see Location} hierarchy.
 *
 * Operators describe the physical/logical Archive layout (Repository →
 * Room → Shelf, or Museum → Showcase) in a spreadsheet and upload it
 * once at setup. The Locations table has a `parent_id` self-FK + a
 * materialised `path` column the model recomputes in a saving hook —
 * so the importer only has to resolve the human-readable parent name
 * to a FK id and let the model do the rest.
 *
 * Two-pass strategy:
 *   1. Sort the operator's rows by depth (rows with `parent_name` blank
 *      first, then their children, etc.). The Filament import job
 *      processes rows in the order they sit in the spreadsheet — if a
 *      parent comes after its child, the resolve will fail. Operators
 *      can either pre-sort the file OR rely on this importer's
 *      idempotency: re-running the import resolves any rows that
 *      previously failed because the parent had not yet been created.
 *   2. Resolve `parent_id` by exact case-insensitive `name` match
 *      scoped to the same `repository_id`. Reject the row with a clear
 *      error if the parent name does not exist yet (operator re-runs
 *      after the missing parents are imported).
 *
 * Idempotency: matching by composite key (repository_id, parent_id,
 * name). Re-importing the same file updates `type` / `notes` /
 * `sort_order` / `is_active` in place; the materialised `path` is
 * recomputed by the model's saving hook.
 */
class LocationImporter extends Importer
{
    use SkipsExistingRows;

    protected static ?string $model = Location::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('Location name')
                ->requiredMapping()
                ->guess(['Name', 'name', 'Location', 'Location name'])
                ->rules(['required', 'string', 'max:191']),

            ImportColumn::make('type')
                ->label('Type (' . implode('|', Location::TYPES) . ')')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Type', 'type', 'Location type', 'Kind'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null) {
                        return null;
                    }
                    $candidate = mb_strtolower(trim($state));
                    // Accept legacy synonyms operators might paste.
                    $aliases = [
                        'archive' => 'repository',
                        'archive room' => 'room',
                        'storage' => 'shelf',
                        'display case' => 'showcase',
                        'vetrina' => 'showcase',
                        'museo' => 'museum',
                        'lab' => 'conservation',
                        'conservazione' => 'conservation',
                        'temporary' => 'temp_holding',
                    ];
                    $candidate = $aliases[$candidate] ?? $candidate;

                    return in_array($candidate, Location::TYPES, true) ? $candidate : null;
                })
                ->rules(['required', 'string', 'in:' . implode(',', Location::TYPES)]),

            ImportColumn::make('parent_name')
                ->label('Parent location name (blank for root)')
                ->guess(['Parent', 'parent', 'Parent name', 'parent_name', 'Parent location']),

            ImportColumn::make('repository_code')
                ->label('Repository code (e.g. NRA)')
                ->guess(['Repository', 'repository', 'Repo code', 'repository_code']),

            ImportColumn::make('code')
                ->label('Short code')
                ->guess(['Code', 'code', 'Short code'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Notes', 'notes', 'Description'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('sort_order')
                ->label('Sort order')
                ->guess(['Sort', 'Sort order', 'Order', 'sort_order'])
                ->numeric()
                ->rules(['nullable', 'integer']),

            ImportColumn::make('is_active')
                ->label('Is active?')
                ->guess(['Active', 'Is active', 'is_active'])
                ->boolean()
                ->rules(['nullable', 'boolean']),
        ];
    }

    /**
     * Resolve `repository_id` from the operator-supplied repository code,
     * then `parent_id` from the parent location name scoped to that
     * repository. Throws ValidationException with a clear message when
     * either lookup fails.
     */
    public function afterFill(): void
    {
        /** @var Location $record */
        $record = $this->record;

        $repoCode = trim((string) ($this->data['repository_code'] ?? ''));
        if ($repoCode !== '') {
            $repository = Repository::query()
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($repoCode)])
                ->first();
            if ($repository === null) {
                throw ValidationException::withMessages([
                    'repository_code' => "Repository '{$repoCode}' not found.",
                ]);
            }
            $record->repository_id = (int) $repository->getKey();
        }

        $parentName = trim((string) ($this->data['parent_name'] ?? ''));
        if ($parentName !== '') {
            $parentQuery = Location::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($parentName)]);

            if ($record->repository_id !== null) {
                $parentQuery->where(function ($q) use ($record): void {
                    $q->where('repository_id', $record->repository_id)
                        ->orWhereNull('repository_id');
                });
                // Determinism: when a same-named location exists both
                // repository-scoped AND globally, prefer the repository-
                // scoped one (CodeRabbit PR #85 finding).
                $parentQuery->orderByRaw(
                    'CASE WHEN repository_id = ? THEN 0 WHEN repository_id IS NULL THEN 1 ELSE 2 END',
                    [$record->repository_id]
                );
            }

            $parent = $parentQuery->first();
            if ($parent === null) {
                throw ValidationException::withMessages([
                    'parent_name' => "Parent location '{$parentName}' not found. Import the parents first, then re-run.",
                ]);
            }
            $record->parent_id = (int) $parent->getKey();
        }

        if ($record->is_active === null) {
            $record->is_active = true;
        }
    }

    /**
     * Idempotent match on (repository_id, parent_id, name). Re-running the
     * same file updates existing rows.
     */
    public function resolveRecord(): ?Location
    {
        $name = trim((string) ($this->data['name'] ?? ''));
        if ($name === '') {
            return new Location;
        }

        // Match the docblock contract: idempotency on the COMPOSITE key
        // (repository_id, parent_id, name). Matching by name alone would
        // pick up a same-named location in a different repository or under
        // a different parent and overwrite it — critical cross-tenant
        // corruption risk (CodeRabbit PR #85 finding).
        $repoCode = trim((string) ($this->data['repository_code'] ?? ''));
        $repoId = null;
        if ($repoCode !== '') {
            $repoId = Repository::query()
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($repoCode)])
                ->value('id');
        }

        $parentName = trim((string) ($this->data['parent_name'] ?? ''));
        $parentId = null;
        if ($parentName !== '') {
            $parentId = Location::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($parentName)])
                ->when($repoId !== null, fn ($q) => $q->where(function ($qq) use ($repoId): void {
                    $qq->where('repository_id', $repoId)
                        ->orWhereNull('repository_id');
                }))
                ->value('id');
        }

        $existing = Location::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('repository_id', $repoId)
            ->where('parent_id', $parentId)
            ->first();

        $record = $existing ?? new Location;
        $this->skipIfDuplicate($record);

        return $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Locations import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed (parents may be missing — re-run after fixing the order).';
        }

        return $body;
    }
}
