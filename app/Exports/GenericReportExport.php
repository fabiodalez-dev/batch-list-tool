<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Reusable Excel exporter for the canned reports.
 *
 * Every Report Page hands this class three things:
 *
 *   - a flat iterable of model rows already filtered by the page's
 *     `getFilteredTableQuery()` (or `reportQuery()` for the grouped
 *     reports);
 *   - a `[Header => closure(row)]` map declaring the columns;
 *   - a sheet title for the workbook tab.
 *
 * The class is a thin glue layer over Maatwebsite/Excel; no per-report
 * subclass is needed. The headers and the value-mapping closures are
 * the single source of truth for column order — the exporter loops
 * over them in array-order.
 *
 * @implements WithMapping<mixed>
 */
final class GenericReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param iterable<int, mixed> $rows
     * @param array<string, callable(mixed): mixed> $columns ordered map of header label → value extractor
     */
    public function __construct(
        protected iterable $rows,
        protected array $columns,
        protected string $title = 'Report',
    ) {}

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        if ($this->rows instanceof Collection) {
            return $this->rows;
        }

        return new Collection(is_array($this->rows) ? $this->rows : iterator_to_array($this->rows, preserve_keys: false));
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_keys($this->columns);
    }

    /**
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $out = [];
        foreach ($this->columns as $closure) {
            $out[] = $this->normaliseCell($closure($row));
        }

        return $out;
    }

    public function title(): string
    {
        // Excel sheet titles are capped at 31 characters and may not contain
        // any of: : \ / ? * [ ]. Sanitise so a long report title doesn't
        // blow up PhpSpreadsheet.
        $clean = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $this->title) ?? 'Report';

        return mb_substr(trim($clean), 0, 31) ?: 'Report';
    }

    /**
     * Coerce a value into something Excel can sensibly render.
     * Carbon → "Y-m-d H:i", arrays → JSON, objects with __toString → string.
     */
    protected function normaliseCell(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value;
    }
}
