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
        .empty { text-align: center; color: #6b7280; font-style: italic; padding: 8mm 2mm; }
        .summary { margin-top: 4mm; font-size: 8pt; color: #6b7280; }
    </style>
</head>
<body>
    <header>
        <span class="title">{{ $title }}</span>
        <div class="meta">
            Generated {{ $generated_at }} &middot; By {{ $generated_by }}
            &middot; Notarial Registers Archive (NRA) &mdash; Batch List Tool
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

        @if (empty($rows))
            <div class="empty">No data available for this report.</div>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach ($headers as $h)
                            <th>{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
