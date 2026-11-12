{{--
    RFQ §3.1.10 — shared PDF layout for canned reports.

    All five report Pages share this single blade. The PDF is rendered
    by barryvdh/laravel-dompdf in A4 portrait. Page numbers come from
    a DomPDF inline-PHP script block at the bottom that uses page_text
    on every page (the only reliable way to get N-of-M numbering with
    DomPDF — CSS counter(pages) isn't fully supported).

    Variables expected:
      - $title         (string)  report title shown in the header
      - $subtitle      (?string) optional secondary line (e.g. filter summary)
      - $headers       (array<int, string>) column display labels
      - $rows          (iterable<int, array<int, scalar|null>>) flat row data
      - $generated_at  (string)  "Y-m-d H:i"
      - $generated_by  (string)  user identity that triggered the export
      - $total_rows    (int)     count(rows)
      - $truncation_notice (?string) set when the PDF holds only the first rows
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 14mm 22mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; }
        header { position: fixed; top: -12mm; left: 0; right: 0; height: 12mm; border-bottom: 1px solid #d1d5db; padding-bottom: 2mm; }
        header .title { font-size: 12pt; font-weight: bold; color: #111827; }
        header .meta { font-size: 8pt; color: #6b7280; margin-top: 1mm; }
        footer { position: fixed; bottom: -16mm; left: 0; right: 0; height: 12mm; border-top: 1px solid #d1d5db; padding-top: 2mm; font-size: 8pt; color: #6b7280; }
        footer .brand { float: left; }
        h1 { font-size: 14pt; margin: 0 0 2mm 0; color: #111827; }
        .subtitle { font-size: 9pt; color: #6b7280; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
        thead th { background: #f3f4f6; border-bottom: 1.5px solid #9ca3af; text-align: left; padding: 2mm 2mm; font-size: 9pt; color: #111827; }
        tbody td { border-bottom: 0.5px solid #e5e7eb; padding: 1.5mm 2mm; vertical-align: top; word-wrap: break-word; }
        tbody tr:nth-child(even) td { background: #fafafa; }
        tbody tr.group td { background: #e5e7eb; font-weight: bold; color: #111827; padding-top: 2.5mm; border-bottom: 1px solid #9ca3af; }
        .empty { text-align: center; color: #6b7280; font-style: italic; padding: 8mm 2mm; }
        .summary { margin-top: 4mm; font-size: 8pt; color: #6b7280; }
        .notice { margin-bottom: 3mm; padding: 2mm 3mm; border: 1px solid #d97706; background: #fffbeb; color: #92400e; font-size: 8.5pt; }
    </style>
</head>
<body>
    <header>
        <span class="title">{{ $title }}</span>
        <div class="meta">
            Generated {{ $generated_at }} &middot; By {{ $generated_by }}
            &middot; Notarial Registers Archive (NRA) &mdash; Logistics Tool
        </div>
    </header>

    <footer>
        <span class="brand">NRA &mdash; NAF Malta &middot; Confidential</span>
    </footer>

    <main>
        <h1>{{ $title }}</h1>
        @if (! empty($subtitle))
            <div class="subtitle">{{ $subtitle }}</div>
        @endif

        @if (! empty($truncation_notice))
            <div class="notice">{{ $truncation_notice }}</div>
        @endif

        @if (empty($rows))
            <div class="empty">No data available for this report.</div>
        @else
            @php($groupBy = $group_by ?? null)
            @php($currentGroup = null)
            {{-- DomPDF lays out a table as one block, and its memory grows
                 faster than the row count: one 500-row table took over
                 700 MB and the host stops a request at 512 MB. A fresh
                 table every 50 rows keeps each layout small; the header
                 repeats at the top of each chunk. --}}
            @foreach (array_chunk($rows, 50) as $chunk)
            <table>
                <thead>
                    <tr>
                        @foreach ($headers as $i => $h)
                            @if ($groupBy === null || $i !== $groupBy)
                                <th>{{ $h }}</th>
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chunk as $row)
                        @if ($groupBy !== null && ($currentGroup === null || (string) ($row[$groupBy] ?? '') !== $currentGroup))
                            @php($currentGroup = (string) ($row[$groupBy] ?? ''))
                            <tr class="group">
                                <td colspan="{{ count($headers) - 1 }}">
                                    {{ $headers[$groupBy] }}: {{ $currentGroup !== '' ? $currentGroup : 'none' }}
                                    &middot; {{ $group_counts[$currentGroup] ?? 0 }} {{ $group_noun ?? 'rows' }}
                                </td>
                            </tr>
                        @endif
                        <tr>
                            @foreach ($row as $i => $cell)
                                @if ($groupBy === null || $i !== $groupBy)
                                    <td>{{ $cell ?? '' }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @endforeach
            <div class="summary">Total rows: {{ $total_rows }}</div>
        @endif
    </main>

    {{-- Per-page numbering: DomPDF's inline PHP block runs once and is
         invoked on each page, so we get "Page N of M" in the footer
         without needing CSS counter(pages) support. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
            $size = 8;
            $color = [0.42, 0.45, 0.5];
            $pdf->page_text(
                $pdf->get_width() - 90,
                $pdf->get_height() - 30,
                'Page {PAGE_NUM} of {PAGE_COUNT}',
                $font,
                $size,
                $color
            );
        }
    </script>
</body>
</html>
