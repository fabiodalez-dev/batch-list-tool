<?php

declare(strict_types=1);

use App\Exports\GenericReportExport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Security regression (schema/import review 2026-07-22) — every XLSX report
 * export runs through GenericReportExport. maatwebsite/PhpSpreadsheet writes a
 * string starting with '=' (or +, -, @) as a LIVE formula, so an
 * attacker-controlled value imported into a document note / identifier
 * ("=HYPERLINK(...)", "=cmd|'/c calc'!A1") would execute when the operator
 * opens the exported report. normaliseCell() must neutralise it — the same
 * defence the CSV exporter already applies.
 */
it('neutralises formula-leading cells in the XLSX export, leaving normal text intact', function (): void {
    $rows = new Collection([
        ['v' => '=1+1'],
        ['v' => '+2'],
        ['v' => '-3'],
        ['v' => '@SUM(A1:A2)'],
        ['v' => "\t=danger"],
        ['v' => 'harmless text'],
        ['v' => 'a=b (not leading)'],
        ['v' => 'R642/001'],
    ]);

    $file = storage_path('app/private/formula_probe_' . uniqid() . '.xlsx');
    Excel::store(new GenericReportExport($rows, ['V' => fn (array $r) => $r['v']], 'Probe'), basename($file), 'local');

    try {
        $sheet = IOFactory::load($file)->getActiveSheet();

        // No dangerous cell may be a live formula.
        foreach (['A2', 'A3', 'A4', 'A5', 'A6'] as $coord) {
            expect($sheet->getCell($coord)->isFormula())->toBeFalse("cell {$coord} became a live formula");
        }
        // Dangerous ones are prefixed with a single quote (literal text).
        expect($sheet->getCell('A2')->getValue())->toBe("'=1+1")
            ->and($sheet->getCell('A3')->getValue())->toBe("'+2");
        // Legitimate values are untouched.
        expect($sheet->getCell('A7')->getValue())->toBe('harmless text')
            ->and($sheet->getCell('A8')->getValue())->toBe('a=b (not leading)')
            ->and($sheet->getCell('A9')->getValue())->toBe('R642/001');
    } finally {
        @unlink($file);
    }
});
