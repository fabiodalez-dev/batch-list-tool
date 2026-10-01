<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use App\Support\History\Timeline;
use App\Support\Reports\ReportRenderer;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "History" tab of a box or a document: one chronology of everything that
 * happened to it (see Timeline), newest first, filterable by kind of event and
 * searchable, with the whole of it one click away as a CSV.
 */
final class HistoryTimelineTable
{
    /**
     * @param Closure(): Model $owner the box or document whose history this is
     * @param Closure(Model): Collection<int, array<string, mixed>> $build
     */
    public static function table(Table $table, Closure $owner, Closure $build, string $name): Table
    {
        return $table
            ->records(function (?array $filters, ?string $search, int|string $page, int|string $recordsPerPage) use ($owner, $build): LengthAwarePaginator {
                $entries = self::filtered($build($owner()), $filters['kind']['values'] ?? [], $search);
                $perPage = is_numeric($recordsPerPage) ? (int) $recordsPerPage : max(1, $entries->count());
                $page = max(1, (int) $page);

                return new LengthAwarePaginator(
                    $entries->forPage($page, $perPage)->map(fn (array $e): array => self::row($e))->values()->all(),
                    $entries->count(),
                    $perPage,
                    $page,
                );
            })
            ->heading('History')
            ->description('Everything recorded about this ' . $name . ', newest first: field changes from the audit trail, barcodes, seals, locations, box moves, identifiers and flags.')
            ->emptyStateHeading('Nothing recorded yet')
            ->columns([
                Tables\Columns\TextColumn::make('at')
                    ->label('When')
                    ->placeholder('Undated (legacy sheet)')
                    ->wrap(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Timeline::KIND_CREATED, Timeline::KIND_RESTORED => 'success',
                        Timeline::KIND_DELETED => 'danger',
                        Timeline::KIND_FLAG => 'warning',
                        Timeline::KIND_MOVE, Timeline::KIND_LOCATION => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('subject')
                    ->label('What')
                    ->wrap(),
                Tables\Columns\TextColumn::make('from')
                    ->label('From')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('to')
                    ->label('To')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('by')
                    ->label('By')
                    ->placeholder('system'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Event')
                    ->multiple()
                    ->options(array_combine(self::kinds(), self::kinds())),
            ])
            ->searchable()
            ->headerActions([
                Action::make('export_history')
                    ->label('Export history (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (): StreamedResponse => self::export($build($owner()), $name, $owner())),
            ])
            // Timeline rows are plain arrays, not models: a relation manager's
            // default click-through (open the related record) has nothing to open.
            ->recordAction(null)
            ->recordUrl(null)
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            Timeline::KIND_CHANGE, Timeline::KIND_CREATED, Timeline::KIND_DELETED, Timeline::KIND_RESTORED,
            Timeline::KIND_LOCATION, Timeline::KIND_BARCODE, Timeline::KIND_SEAL, Timeline::KIND_MOVE,
            Timeline::KIND_IDENTIFIER, Timeline::KIND_FLAG,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $entries
     * @param array<int, string> $kinds
     * @return Collection<int, array<string, mixed>>
     */
    public static function filtered(Collection $entries, array $kinds, ?string $search): Collection
    {
        $kinds = array_values(array_filter($kinds));
        $needle = Str::lower(trim((string) $search));

        if ($kinds !== []) {
            $entries = $entries->filter(fn (array $e): bool => in_array($e['kind'], $kinds, true));
        }

        if ($needle !== '') {
            $entries = $entries->filter(fn (array $e): bool => Str::contains(
                Str::lower(implode(' ', [$e['subject'], $e['from'], $e['to'], $e['by'], $e['source']])),
                $needle,
            ));
        }

        return $entries->values();
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function row(array $entry): array
    {
        return [
            '__key' => $entry['key'],
            'at' => $entry['at'] instanceof CarbonInterface ? $entry['at']->format('Y-m-d H:i') : null,
            'kind' => $entry['kind'],
            'subject' => $entry['subject'],
            'from' => $entry['from'],
            'to' => $entry['to'],
            'by' => $entry['by'],
            'source' => $entry['source'],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $entries
     */
    private static function export(Collection $entries, string $name, Model $owner): StreamedResponse
    {
        $label = (string) ($owner->getAttribute('identifier') ?? $owner->getAttribute('barcode') ?? $owner->getAttribute('box_number') ?? $owner->getKey());
        $filename = sprintf('history_%s_%s_%s.csv', $name, Str::slug($label, '_'), now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($entries): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['When', 'Event', 'What', 'From', 'To', 'By', 'Source'], escape: '\\');
            foreach ($entries as $e) {
                $r = self::row($e);
                fputcsv($out, array_map(
                    fn ($v): string => ReportRenderer::sanitizeCsvCell($v),
                    [$r['at'] ?? 'Undated (legacy sheet)', $r['kind'], $r['subject'], $r['from'], $r['to'], $r['by'] ?? 'system', $r['source']],
                ), escape: '\\');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
