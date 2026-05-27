<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\Imports\Events\ImportCompleted;
use Filament\Actions\Imports\Events\ImportStarted;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use League\Csv\Reader;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.3 (Bulk Import v2) — multi-step guided import.
 *
 * Real Filament 5 Wizard with five sequential Steps:
 *
 *   1. What are you importing?
 *   2. Download the template
 *   3. Upload your filled file
 *   4. Preview
 *   5. Confirm and import
 *
 * The per-Resource `FullImportAction` stays as a power-user shortcut on
 * each List page; this Wizard is the primary guided path for operators
 * onboarding a fresh tenant or running a bulk re-import.
 *
 * Why a Page (not a Resource):
 *
 *   - It's a workflow, not a model. There is no `import_wizard` table.
 *   - Filament Pages let us host a Wizard schema directly while still
 *     getting navigation registration + Shield permission discovery.
 *
 * Dispatch model: at submit we replicate the import-action's batch
 * dispatch (the same code path Filament's built-in `ImportAction`
 * uses), targeting whichever {@see Importer} class matches the
 * wizard's `import_type`. For .xlsx files we convert to a temporary
 * CSV first so the stock {@see ImportCsv} job can read it row-by-row
 * — this keeps us off the HayderHatem streaming path (which has its
 * own form schema we cannot reuse without re-rendering inside the
 * Wizard).
 *
 * @property-read Schema $form
 */
class ImportWizard extends Page
{
    /**
     * Map of `import_type` keys → the Importer class that handles them.
     *
     * @var array<string, class-string<Importer>>
     */
    public const IMPORTERS = [
        'series' => SeriesImporter::class,
        'authorities' => AuthorityImporter::class,
        'batches' => BatchImporter::class,
        'boxes' => BoxImporter::class,
        'documents' => DocumentImporter::class,
    ];

    /**
     * Map of `import_type` → the entity key the {@see TemplateGenerator}
     * uses (singular, matches the per-Resource header action).
     *
     * @var array<string, string>
     */
    public const TEMPLATE_KEYS = [
        'series' => 'series',
        'authorities' => 'authority',
        'batches' => 'batch',
        'boxes' => 'box',
        'documents' => 'document',
    ];

    /**
     * Wizard state — a single flat array of every step's fields.
     * Filament's Wizard component stitches the steps' state paths
     * together under this root, so `data.import_type`, `data.file`
     * etc are addressable from both the schema definition and the
     * submit handler.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    protected string $view = 'filament.pages.import-wizard';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Import Wizard';

    protected static ?string $title = 'Import Wizard';

    protected static ?string $slug = 'import-wizard';

    /**
     * Admin / super_admin only — the wizard runs imports that affect
     * every tenant, so we keep it off-limits for editors and viewers.
     * Shield auto-discovers this Page for permission generation.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        // CLI / queue discovery has no authenticated user; keep
        // registration on by default so artisan introspection works.
        if (auth()->guest()) {
            return true;
        }

        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill([
            'skip_duplicates' => true,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Schema — the Wizard itself */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Default schema name resolved by Filament's `InteractsWithSchemas`
     * trait. Mounting a Page via `Livewire::test()` reads `$this->form`
     * which dispatches to this method.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    $this->stepWhat(),
                    $this->stepDownloadTemplate(),
                    $this->stepUpload(),
                    $this->stepPreview(),
                    $this->stepConfirm(),
                ])
                    ->submitAction(
                        FilamentAction::make('startImport')
                            ->label('Start import')
                            ->color('primary')
                            ->icon('heroicon-o-arrow-up-tray')
                            ->action(fn () => $this->startImport())
                    )
                    ->skippable(false),
            ]);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Submit: dispatch the importer */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Wizard submit handler. Replicates the dispatch loop of Filament's
     * native `ImportAction` (with .xlsx → CSV transcoding when needed)
     * so the operator's rows go through the same job batch the per-Resource
     * action uses.
     */
    public function startImport(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $state = $this->form->getState();
        } catch (Halt) {
            return;
        }

        $type = (string) ($state['import_type'] ?? '');
        if (! array_key_exists($type, self::IMPORTERS)) {
            $this->notifyDanger('Pick a type in step 1 first.');

            return;
        }

        /** @var TemporaryUploadedFile|array<TemporaryUploadedFile>|null $file */
        $file = $state['file'] ?? null;
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }
        if (! $file instanceof TemporaryUploadedFile) {
            $this->notifyDanger('No file uploaded — go back to step 3.');

            return;
        }

        try {
            $csvPath = $this->materialiseCsv($file, (int) ($state['sheet'] ?? 0));
        } catch (\Throwable $e) {
            $this->notifyDanger('Could not read the file: ' . $e->getMessage());

            return;
        }

        try {
            [$headers, $rows] = $this->readCsvForImport($csvPath);
        } catch (\Throwable $e) {
            $this->notifyDanger('Could not parse the file rows: ' . $e->getMessage());

            return;
        }

        /** @var class-string<Importer> $importerClass */
        $importerClass = self::IMPORTERS[$type];
        $columnMap = self::guessColumnMap($importerClass, $headers);
        $missing = self::findMissingRequiredColumns($importerClass, $columnMap);
        if (count($missing) > 0) {
            $this->notifyDanger(
                'Missing required columns: ' . implode(', ', $missing) . '.'
            );

            return;
        }

        try {
            $importId = $this->dispatchImportBatch(
                importerClass: $importerClass,
                fileName: $file->getClientOriginalName(),
                filePath: $csvPath,
                rows: $rows,
                columnMap: $columnMap,
                options: ['skip_duplicates' => (bool) ($state['skip_duplicates'] ?? true)],
            );
        } catch (\Throwable $e) {
            $this->notifyDanger('Import dispatch failed: ' . $e->getMessage());

            return;
        }

        Notification::make()
            ->title('Import started')
            ->body(sprintf('Queued %d rows of %s. Watch the notifications tray for progress.', count($rows), $type))
            ->success()
            ->send();

        // Reset the wizard to step 1 by clearing state — we intentionally do
        // not redirect to a Filament Imports resource URL because that route
        // may not be registered in this panel, and accepting any user-shaped
        // URL here would be an open-redirect risk.
        $this->data = [];
        $this->form->fill(['skip_duplicates' => true]);

        // The local var is kept for forwards-compat with future versions of
        // this Page that may surface the Import ID to the operator (e.g. in
        // a dedicated "Recent imports" panel). Silence the unused warning.
        unset($importId);
    }

    /**
     * Build the `columnMap` array Filament's import jobs expect:
     * keys are Importer column names, values are the matching Excel
     * header strings (or null if not present).
     *
     * @param class-string<Importer> $importerClass
     * @param array<int, string> $excelHeaders
     * @return array<string, string|null>
     */
    public static function guessColumnMap(string $importerClass, array $excelHeaders): array
    {
        $lowerExcel = [];
        foreach ($excelHeaders as $h) {
            $lowerExcel[mb_strtolower(trim($h))] = $h;
        }

        $map = [];
        foreach ($importerClass::getColumns() as $column) {
            $map[$column->getName()] = self::guessSingleColumn($column, $lowerExcel);
        }

        return $map;
    }

    /**
     * @param class-string<Importer> $importerClass
     * @param array<string, string|null> $columnMap
     * @return array<int, string>
     */
    public static function findMissingRequiredColumns(string $importerClass, array $columnMap): array
    {
        $missing = [];
        foreach ($importerClass::getColumns() as $column) {
            if (! $column->isMappingRequired()) {
                continue;
            }
            if (blank($columnMap[$column->getName()] ?? null)) {
                $missing[] = (string) ($column->getLabel() ?? $column->getName());
            }
        }

        return $missing;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Compat shims for legacy callers (tests, blade includes) */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * @deprecated Use {@see TemplateGenerator::download()} directly. Kept
     * for the few blade snippets that still call `$page->downloadTemplate`.
     */
    public function downloadTemplate(string $entity): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return TemplateGenerator::download($entity);
    }

    /**
     * Used only by tests / external callers wanting a quick "where would
     * this importer's UI live" URL.
     *
     * @param class-string $resource
     */
    public static function importerUrl(string $resource): string
    {
        if (! class_exists($resource)) {
            return '#';
        }
        if (! method_exists($resource, 'getUrl')) {
            return '#';
        }

        /** @phpstan-ignore-next-line */
        return $resource::getUrl('index');
    }

    /* ──── Step 1: what are you importing? ───────────────────────── */

    protected function stepWhat(): Step
    {
        return Step::make('What are you importing?')
            ->description('Pick the entity. Order matters: Series → Authorities → Batches → Boxes → Documents.')
            ->icon('heroicon-o-queue-list')
            ->schema([
                Radio::make('import_type')
                    ->label('Type of records')
                    ->options([
                        'series' => 'Series (record types: R / REG / RWL / O)',
                        'authorities' => 'Authorities (notaries — 808 in production sample)',
                        'batches' => 'Batches (numbered groupings)',
                        'boxes' => 'Boxes (physical containers)',
                        'documents' => 'Documents (the main entity — 3,113 rows in sample)',
                    ])
                    ->descriptions([
                        'series' => 'Depends on: nothing — import this first.',
                        'authorities' => 'Depends on: nothing.',
                        'batches' => 'Depends on: at least one Repository.',
                        'boxes' => 'Depends on: at least one Batch.',
                        'documents' => 'Depends on: Series + Authorities + Batches + Boxes.',
                    ])
                    ->required()
                    ->live(),
            ]);
    }

    /* ──── Step 2: download the template ─────────────────────────── */

    protected function stepDownloadTemplate(): Step
    {
        return Step::make('Download the template')
            ->description('Get a blank Excel file with the exact column headers we expect.')
            ->icon('heroicon-o-arrow-down-tray')
            ->schema([
                Placeholder::make('template_info')
                    ->label('What this template contains')
                    ->content(function (Get $get): HtmlString {
                        $type = (string) ($get('import_type') ?? '');
                        if ($type === '' || ! array_key_exists($type, self::TEMPLATE_KEYS)) {
                            return new HtmlString('<em>Choose a type in step 1 first.</em>');
                        }
                        $entity = self::TEMPLATE_KEYS[$type];
                        $headers = TemplateGenerator::headersFor($entity);

                        $items = '';
                        foreach ($headers as $h) {
                            $label = $h === '' ? '<em>(blank column)</em>' : e($h);
                            $items .= '<li>' . $label . '</li>';
                        }

                        return new HtmlString(
                            '<p class="text-sm">The template will have '
                            . count($headers)
                            . ' header columns:</p>'
                            . '<ul class="mt-2 list-disc pl-5 text-sm space-y-0.5">'
                            . $items
                            . '</ul>'
                        );
                    }),

                SchemaActions::make([
                    FilamentAction::make('download_template')
                        ->label('Download template')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (Get $get): bool => filled($get('import_type')))
                        ->action(function (Get $get): ?StreamedResponse {
                            $type = (string) ($get('import_type') ?? '');
                            if (! array_key_exists($type, self::TEMPLATE_KEYS)) {
                                return null;
                            }

                            return TemplateGenerator::download(self::TEMPLATE_KEYS[$type]);
                        }),
                ]),
            ]);
    }

    /* ──── Step 3: upload your filled file ───────────────────────── */

    protected function stepUpload(): Step
    {
        return Step::make('Upload your filled file')
            ->description('Drop in the .xlsx or .csv file. Files up to 50 MB are accepted.')
            ->icon('heroicon-o-document-arrow-up')
            ->schema([
                FileUpload::make('file')
                    ->label('Spreadsheet file')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'application/csv',
                        'text/plain',
                    ])
                    ->maxSize(50 * 1024)
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->preserveFilenames()
                    ->required()
                    ->live(),

                Select::make('sheet')
                    ->label('Sheet to import')
                    ->helperText('Detected multiple worksheets — pick the one with your data.')
                    ->options(function (Get $get): array {
                        $file = $get('file');
                        $sheets = self::detectSheetNames($file);
                        if (count($sheets) <= 1) {
                            return [];
                        }

                        return array_combine(
                            array_map('strval', array_keys($sheets)),
                            $sheets,
                        );
                    })
                    ->visible(function (Get $get): bool {
                        return count(self::detectSheetNames($get('file'))) > 1;
                    }),
            ]);
    }

    /* ──── Step 4: preview ───────────────────────────────────────── */

    protected function stepPreview(): Step
    {
        return Step::make('Preview')
            ->description('Spot-check the first 10 rows before we run the import.')
            ->icon('heroicon-o-eye')
            ->schema([
                Placeholder::make('row_count')
                    ->label('Detected rows')
                    ->content(function (Get $get): HtmlString {
                        $info = $this->parseFilePreview(
                            $get('file'),
                            (int) ($get('sheet') ?? 0),
                        );
                        if ($info === null) {
                            return new HtmlString('<em>Upload a file in step 3 first.</em>');
                        }

                        return new HtmlString(sprintf(
                            'We detected <strong>%d</strong> data rows in <strong>%d</strong> columns.',
                            max(0, $info['totalRows']),
                            count($info['headers']),
                        ));
                    }),

                Placeholder::make('preview_table')
                    ->label('First 10 rows')
                    ->content(function (Get $get): HtmlString {
                        $info = $this->parseFilePreview(
                            $get('file'),
                            (int) ($get('sheet') ?? 0),
                        );
                        if ($info === null) {
                            return new HtmlString('<em>(no file yet)</em>');
                        }

                        return new HtmlString(self::renderPreviewTable($info['headers'], $info['rows']));
                    }),

                Placeholder::make('column_mapping')
                    ->label('Column mapping')
                    ->content(function (Get $get): HtmlString {
                        $type = (string) ($get('import_type') ?? '');
                        if (! array_key_exists($type, self::IMPORTERS)) {
                            return new HtmlString('<em>Pick a type in step 1 first.</em>');
                        }
                        $info = $this->parseFilePreview(
                            $get('file'),
                            (int) ($get('sheet') ?? 0),
                        );
                        if ($info === null) {
                            return new HtmlString('<em>Upload a file first.</em>');
                        }
                        $mapping = self::guessColumnMap(self::IMPORTERS[$type], $info['headers']);
                        $missing = self::findMissingRequiredColumns(self::IMPORTERS[$type], $mapping);

                        $rows = '';
                        foreach ($mapping as $field => $excelCol) {
                            $rows .= '<li><code>' . e($field) . '</code> ← '
                                . ($excelCol === null ? '<em>(unmapped)</em>' : '<strong>' . e((string) $excelCol) . '</strong>')
                                . '</li>';
                        }
                        $warn = '';
                        if (count($missing) > 0) {
                            $warn = '<p class="mt-2 text-sm font-medium text-danger-600">'
                                . 'Missing required columns: <code>'
                                . e(implode(', ', $missing))
                                . '</code>. Re-upload after adding them.</p>';
                        }

                        return new HtmlString(
                            '<ul class="text-sm space-y-1">' . $rows . '</ul>' . $warn
                        );
                    }),
            ]);
    }

    /* ──── Step 5: confirm and import ────────────────────────────── */

    protected function stepConfirm(): Step
    {
        return Step::make('Confirm and import')
            ->description('Final check. Hit "Start import" to dispatch the job batch.')
            ->icon('heroicon-o-check-badge')
            ->schema([
                Placeholder::make('summary')
                    ->label('Summary')
                    ->content(function (Get $get): HtmlString {
                        $type = (string) ($get('import_type') ?? '(none)');
                        $info = $this->parseFilePreview(
                            $get('file'),
                            (int) ($get('sheet') ?? 0),
                        );
                        $rowCount = $info['totalRows'] ?? 0;

                        return new HtmlString(sprintf(
                            'About to import <strong>%d</strong> rows of <strong>%s</strong>.',
                            $rowCount,
                            e($type),
                        ));
                    }),

                Checkbox::make('confirm')
                    ->label('I have reviewed the preview and I want to import these rows.')
                    ->required()
                    ->accepted(),

                Checkbox::make('skip_duplicates')
                    ->label('Skip rows that already exist (matched by the importer\'s resolveRecord).')
                    ->default(true),
            ]);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Helpers */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Detect sheet names from an uploaded .xlsx. Returns `[]` for CSV
     * or when the file cannot be read.
     *
     * @return array<int, string>
     */
    protected static function detectSheetNames(mixed $file): array
    {
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }
        if (! $file instanceof TemporaryUploadedFile) {
            return [];
        }
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            return [];
        }
        $path = $file->getRealPath();
        if (! is_readable($path)) {
            return [];
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            // `listWorksheetNames` is defined on every concrete PhpSpreadsheet
            // reader (Xlsx, Xls, Csv, …) even though it isn't on the IReader
            // interface contract. We dispatch via method_exists to keep
            // PHPStan happy without narrowing the union to one reader class.
            if (! method_exists($reader, 'listWorksheetNames')) {
                return [];
            }

            return (array) $reader->listWorksheetNames($path);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Parse the uploaded file into a (headers + first 10 rows + total row
     * count) preview tuple. Returns null when no file is present.
     *
     * @return array{headers:array<int, string>, rows:array<int, array<int, mixed>>, totalRows:int}|null
     */
    protected function parseFilePreview(mixed $file, int $sheet = 0): ?array
    {
        if (is_array($file)) {
            $file = reset($file) ?: null;
        }
        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }
        $path = $file->getRealPath();
        if (! is_readable($path)) {
            return null;
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $worksheet = $spreadsheet->getSheet($sheet);
            $highestRow = $worksheet->getHighestDataRow();
            $highestColumn = $worksheet->getHighestDataColumn();

            $headers = [];
            foreach ($worksheet->getRowIterator(1, 1) as $row) {
                $iter = $row->getCellIterator('A', $highestColumn);
                $iter->setIterateOnlyExistingCells(false);
                foreach ($iter as $cell) {
                    $headers[] = (string) ($cell->getValue() ?? '');
                }
            }

            $rows = [];
            $maxPreview = 10;
            for ($r = 2; $r <= $highestRow && count($rows) < $maxPreview; $r++) {
                $dataRow = [];
                foreach ($worksheet->getRowIterator($r, $r) as $row) {
                    $iter = $row->getCellIterator('A', $highestColumn);
                    $iter->setIterateOnlyExistingCells(false);
                    foreach ($iter as $cell) {
                        $dataRow[] = $cell->getValue();
                    }
                }
                $rows[] = $dataRow;
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
                'totalRows' => max(0, $highestRow - 1),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Render a small HTML table for the preview placeholder. Output is
     * passed through {@see HtmlString} so the Placeholder renders the
     * markup literally instead of escaping it.
     *
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    protected static function renderPreviewTable(array $headers, array $rows): string
    {
        if (count($headers) === 0) {
            return '<em>(no columns detected)</em>';
        }
        $thead = '';
        foreach ($headers as $h) {
            $thead .= '<th class="px-2 py-1 text-left font-medium bg-gray-100 dark:bg-gray-800">'
                . ($h === '' ? '&nbsp;' : e($h)) . '</th>';
        }
        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($headers as $i => $_h) {
                /** @var mixed $cell */
                $cell = $row[$i] ?? '';
                $tbody .= '<td class="px-2 py-1 border-t border-gray-200 dark:border-gray-700">'
                    . e((string) ($cell ?? '')) . '</td>';
            }
            $tbody .= '</tr>';
        }

        return '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">'
            . '<table class="min-w-full text-xs"><thead><tr>' . $thead . '</tr></thead>'
            . '<tbody>' . $tbody . '</tbody></table></div>';
    }

    /**
     * @param array<string, string> $lowerExcel lowercase-trimmed-header → original-header
     */
    protected static function guessSingleColumn(ImportColumn $column, array $lowerExcel): ?string
    {
        $candidates = [
            $column->getName(),
            $column->getLabel(),
            ...$column->getGuesses(),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $needle = mb_strtolower(trim((string) $candidate));
            if (array_key_exists($needle, $lowerExcel)) {
                return $lowerExcel[$needle];
            }
        }

        return null;
    }

    /**
     * Materialise the uploaded file as a CSV path on the local disk so the
     * stock {@see ImportCsv} job can consume it row-by-row.
     */
    protected function materialiseCsv(TemporaryUploadedFile $file, int $sheet): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('imports');
        $base = 'imports/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $csvAbs = $disk->path($base . '.csv');
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $spreadsheet->setActiveSheetIndex($sheet);
            $writer = new Csv($spreadsheet);
            $writer->setSheetIndex($sheet);
            $writer->save($csvAbs);

            return $csvAbs;
        }

        // CSV / TXT — copy as-is, normalising the extension.
        $csvAbs = $disk->path($base . '.csv');
        copy($file->getRealPath(), $csvAbs);

        return $csvAbs;
    }

    /**
     * @return array{0:array<int, string>, 1:array<int, array<string, string>>}
     */
    protected function readCsvForImport(string $csvAbsPath): array
    {
        $reader = Reader::createFromPath($csvAbsPath, 'r');
        $reader->setHeaderOffset(0);
        $headers = $reader->getHeader();
        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = array_map(static fn ($v) => (string) ($v ?? ''), $record);
        }

        return [$headers, $rows];
    }

    /**
     * @param class-string<Importer> $importerClass
     * @param array<int, array<string, string>> $rows
     * @param array<string, string|null> $columnMap
     * @param array<string, mixed> $options
     */
    protected function dispatchImportBatch(
        string $importerClass,
        string $fileName,
        string $filePath,
        array $rows,
        array $columnMap,
        array $options,
    ): int {
        $user = auth()->user();
        $totalRows = count($rows);

        $import = app(Import::class);
        if ($user !== null) {
            $import->user()->associate($user);
        }
        $import->file_name = $fileName;
        $import->file_path = $filePath;
        $import->importer = $importerClass;
        $import->total_rows = $totalRows;
        $import->save();

        // Clean column map: keep only the non-null entries — the
        // stock ImportCsv job expects string values, not nullables.
        $cleanColumnMap = array_filter(
            $columnMap,
            static fn ($v): bool => $v !== null && $v !== ''
        );

        $importer = $import->getImporter($cleanColumnMap, $options);

        $chunkSize = 100;
        $chunks = array_chunk($rows, $chunkSize);

        $jobs = collect($chunks)->map(fn (array $chunk): object => app(ImportCsv::class, [
            'import' => $import,
            'rows' => base64_encode(serialize($chunk)),
            'columnMap' => $cleanColumnMap,
            'options' => $options,
        ]));

        $import->unsetRelation('user');

        event(new ImportStarted($import, $cleanColumnMap, $options));

        Bus::batch($jobs->all())
            ->allowFailures()
            ->when(
                filled($q = $importer->getJobQueue()),
                fn (PendingBatch $b) => $b->onQueue($q),
            )
            ->when(
                filled($c = $importer->getJobConnection()),
                fn (PendingBatch $b) => $b->onConnection($c),
            )
            ->finally(function () use ($import, $cleanColumnMap, $options): void {
                $fresh = Import::query()->find($import->getKey());
                if ($fresh === null) {
                    return;
                }
                $fresh->touch('completed_at');
                event(new ImportCompleted($fresh, $cleanColumnMap, $options));
            })
            ->dispatch();

        return (int) $import->getKey();
    }

    protected function notifyDanger(string $body): void
    {
        Notification::make()
            ->title('Import not started')
            ->body($body)
            ->danger()
            ->send();
    }
}
