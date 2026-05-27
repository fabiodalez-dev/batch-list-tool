<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports\Filters;

use Filament\Forms;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Universal "from / to" date range filter used across all report Pages.
 *
 * Drop-in usage:
 *
 *     use App\Filament\Pages\Reports\Filters\DateRangeFilter;
 *
 *     DateRangeFilter::make('created_range')
 *         ->label('Created date range')
 *         ->column('created_at')
 *         ->placeholderFrom('From')
 *         ->placeholderTo('To');
 *
 * Semantics:
 *   - both `from` and `to` set → whereBetween($column, [$from, $to])
 *   - only `from` set         → where($column, '>=', $from)
 *   - only `to` set           → where($column, '<=', $to)
 *   - neither set             → no clause
 *
 * Inclusive on both ends. For datetime columns the `to` boundary is
 * extended to the end-of-day so e.g. `to = 2025-04-30` includes any
 * row written on the 30th regardless of clock time.
 *
 * Visual layout: two side-by-side DatePicker fields inside the standard
 * Filament filter dropdown — `columnSpan(['default' => 1, 'md' => 2])`
 * per the project's 2-col rule.
 *
 * This filter is INERT until {@see self::column()} is called by the
 * caller; without a column name we can't build the where-clause, so the
 * `query()` callback is a no-op until the column is set.
 */
class DateRangeFilter extends Filter
{
    /**
     * Name of the DB column to constrain. Set via {@see self::column()}.
     * No default — callers MUST provide one (filter is a no-op otherwise).
     */
    protected ?string $column = null;

    /**
     * Optional human-readable label for the column shown in indicators.
     * If unset we fall back to the column name with underscores cleaned up.
     */
    protected ?string $columnLabel = null;

    protected ?string $placeholderFrom = 'From';

    protected ?string $placeholderTo = 'To';

    /**
     * Treat the `to` boundary as inclusive end-of-day. Defaults to true
     * because all our filter targets are either DATE or DATETIME columns
     * and users expect "to: 2025-04-30" to include 2025-04-30 entries.
     */
    protected bool $inclusiveEndOfDay = true;

    /**
     * The DB column this filter constrains. The filter is a no-op until
     * this method is called by the caller. Returning $this for fluent
     * chaining.
     */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    /**
     * Optional override for the indicator label (defaults to the column
     * name with underscores → spaces, title-cased).
     */
    public function columnLabel(string $label): static
    {
        $this->columnLabel = $label;

        return $this;
    }

    public function placeholderFrom(string $placeholder): static
    {
        $this->placeholderFrom = $placeholder;

        return $this;
    }

    public function placeholderTo(string $placeholder): static
    {
        $this->placeholderTo = $placeholder;

        return $this;
    }

    /**
     * Switch off the "extend `to` to end-of-day" behaviour. Use this
     * when filtering a pure DATE column where the operator semantically
     * means the same day on both ends without time-of-day fuzz.
     */
    public function withoutEndOfDayInclusion(): static
    {
        $this->inclusiveEndOfDay = false;

        return $this;
    }

    /**
     * Public so the per-page tests can invoke the where-building logic
     * without booting a full Livewire stack.
     *
     * @param array{from?: string|null, to?: string|null} $data
     */
    public function applyToQuery(Builder $query, array $data): Builder
    {
        if ($this->column === null) {
            return $query;
        }

        $from = $this->normalizeDate($data['from'] ?? null);
        $to = $this->normalizeDate($data['to'] ?? null, endOfDay: $this->inclusiveEndOfDay);

        if ($from !== null && $to !== null) {
            return $query->whereBetween($this->column, [$from, $to]);
        }

        if ($from !== null) {
            return $query->where($this->column, '>=', $from);
        }

        if ($to !== null) {
            return $query->where($this->column, '<=', $to);
        }

        return $query;
    }

    /**
     * Static helper: callers that build their own bespoke date filter
     * (eg. constraining a subquery) can reuse the same parsing/normalisation
     * rules. Public on purpose.
     */
    public static function normalizeBoundary(mixed $value, bool $endOfDay = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $carbon = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay
            ? $carbon->endOfDay()->format('Y-m-d H:i:s')
            : $carbon->startOfDay()->format('Y-m-d');
    }

    /**
     * Expose the column name (helpful for tests + introspection).
     */
    public function getColumn(): ?string
    {
        return $this->column;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            Forms\Components\DatePicker::make('from')
                ->label(fn (): string => $this->placeholderFrom ?? 'From')
                ->placeholder(fn (): string => $this->placeholderFrom ?? 'From')
                ->native(false)
                ->closeOnDateSelection(),

            Forms\Components\DatePicker::make('to')
                ->label(fn (): string => $this->placeholderTo ?? 'To')
                ->placeholder(fn (): string => $this->placeholderTo ?? 'To')
                ->native(false)
                ->closeOnDateSelection(),
        ]);

        $this->columnSpan(['default' => 1, 'md' => 2]);

        $this->query(function (Builder $query, array $data): Builder {
            return $this->applyToQuery($query, $data);
        });

        $this->indicateUsing(function (array $data): array {
            return $this->buildIndicators($data);
        });
    }

    /**
     * Normalise the form value to a "YYYY-MM-DD HH:MM:SS" string
     * (when end-of-day) or a "YYYY-MM-DD" string. Anything that
     * Carbon can't parse → null so we silently ignore garbage input.
     */
    protected function normalizeDate(mixed $value, bool $endOfDay = false): ?string
    {
        return self::normalizeBoundary($value, $endOfDay);
    }

    /**
     * @param array{from?: string|null, to?: string|null} $data
     * @return array<int, string>
     */
    protected function buildIndicators(array $data): array
    {
        $indicators = [];
        $label = $this->getColumnLabelForIndicator();

        if (! empty($data['from'])) {
            $indicators[] = $label . ' ≥ ' . $data['from'];
        }

        if (! empty($data['to'])) {
            $indicators[] = $label . ' ≤ ' . $data['to'];
        }

        return $indicators;
    }

    protected function getColumnLabelForIndicator(): string
    {
        if ($this->columnLabel !== null) {
            return $this->columnLabel;
        }

        if ($this->column === null) {
            return $this->getLabel() ?: $this->getName();
        }

        return str($this->column)->replace('_', ' ')->title()->toString();
    }
}
