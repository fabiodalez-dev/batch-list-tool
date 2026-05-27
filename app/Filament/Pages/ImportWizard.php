<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\ImportProfile;
use App\Models\User;
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
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use League\Csv\Reader;
use Livewire\Attributes\Url;
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
     * Synonyms table for {@see guessColumnMap()}. Maps lowercase Excel
     * header strings the operator might use → one or more Importer field
     * names. The matcher cascades: exact-name → SYNONYMS → fuzzy
     * Levenshtein. Keys are normalized lowercase / trimmed; values are
     * the importer column names (snake_case) that the synonym resolves to.
     *
     * Most entries are derived from the three sample spreadsheets in
     * `/samples/` and the legacy column names operators have been spotted
     * pasting in (e.g. "Creator Name", "Name of Inputter", "MS code"). The
     * operator can extend this per-profile via `ImportProfile::synonyms`.
     *
     * @var array<string, array<int, string>>
     */
    public const SYNONYMS = [
        // ── Authority / Creator ────────────────────────────────────────
        'creator name' => ['given_names', 'name'],
        'creator first name' => ['given_names'],
        'first name' => ['given_names'],
        'given name' => ['given_names'],
        'given names' => ['given_names'],
        'creator surname' => ['surname'],
        'last name' => ['surname'],
        'family name' => ['surname'],
        'authority name' => ['surname', 'given_names'],
        'authority surname' => ['surname'],
        'name of inputter' => ['inputter', 'created_by'],
        'inputter' => ['inputter', 'created_by'],
        'identifier' => ['identifier', 'code'],
        'authority identifier' => ['identifier'],
        'r-code' => ['identifier'],
        'r code' => ['identifier'],
        'alternative identifier' => ['alternative_identifier'],
        'alt identifier' => ['alternative_identifier'],
        'ms' => ['alternative_identifier'],
        'ms code' => ['alternative_identifier'],
        'type of entity' => ['entity_type'],
        'entity type' => ['entity_type'],
        'practice dates' => ['practice_dates_active'],
        'private practice dates active' => ['practice_dates_active'],
        'dates active' => ['practice_dates_active'],
        'maiden surname' => ['maiden_surname'],
        'maiden name' => ['maiden_surname'],
        'name suffix' => ['name_suffix'],
        'suffix' => ['name_suffix'],
        'ntg dates' => ['ntg_dates_active'],
        'ntg dates active' => ['ntg_dates_active'],

        // ── Document ──────────────────────────────────────────────────
        'batch number' => ['batch_id', 'batch', 'batch_number'],
        'batch no' => ['batch_id', 'batch_number'],
        'batch' => ['batch_id', 'batch_number'],
        'box number' => ['box_id', 'box', 'box_number'],
        'box no' => ['box_id', 'box_number'],
        'box' => ['box_id', 'box_number'],
        'document type' => ['document_type', 'type'],
        'doc type' => ['document_type'],
        'volume' => ['volume_label', 'volume'],
        'volume label' => ['volume_label'],
        'volume number' => ['volume_label'],
        'date of creation' => ['dates'],
        'creation date' => ['dates'],
        'date range' => ['dates'],
        'dates' => ['dates'],
        'catalogue identifier' => ['catalogue_identifier'],
        'catalogue id' => ['catalogue_identifier'],
        'deeds' => ['deeds'],
        'practice' => ['practice'],
        'barcode' => ['barcode'],
        'barcode (in)' => ['barcode'],
        'barcode status' => ['barcode_status'],
        'status' => ['barcode_status'],

        // ── Series ────────────────────────────────────────────────────
        'series code' => ['code'],
        'series identifier' => ['code'],
        'code' => ['code'],
        'series name' => ['title'],
        'series title' => ['title'],
        'title' => ['title'],
        'standard title in english (plural)' => ['title'],
        'standard title' => ['title'],
        'description' => ['description'],
        'legacy label' => ['description'],
        'title and code' => ['description'],
        'is wills series' => ['is_wills_series'],
        'wills' => ['is_wills_series'],
        'is active' => ['is_active'],
        'active' => ['is_active'],

        // ── Batch ─────────────────────────────────────────────────────
        'batch type' => ['type', 'batch_type'],
        'main collection' => ['type'],
        'notary accession' => ['type'],
        'accession' => ['accession_id', 'accession'],

        // ── Box ───────────────────────────────────────────────────────
        'box type' => ['box_type', 'type'],
        'ras box' => ['box_number'],
        'in situ box' => ['box_number'],
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

    /**
     * Optional `?profile=N` query-string parameter — preloads a saved
     * {@see ImportProfile} into the wizard state so the operator's
     * column_map is already populated when they reach Step 4.
     */
    #[Url(as: 'profile')]
    public ?string $profileQuery = null;

    /**
     * ID of the most recent import dispatched by THIS wizard instance.
     * Powers the "Download failed rows" header action — null until the
     * first import in the session runs. Not persisted (Livewire keeps it
     * in component state across re-renders).
     */
    public ?int $lastImportId = null;

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

        $initial = [
            'skip_duplicates' => true,
        ];

        // ?profile=N — preload a saved mapping. We only honour profiles the
        // current user can actually see (owner OR shared in their tenant).
        $profile = $this->resolveProfileFromQuery();
        if ($profile !== null) {
            $initial['starting_profile_id'] = (string) $profile->getKey();
            $initial['import_type'] = $profile->import_type;
            $initial['column_map'] = is_array($profile->column_map) ? $profile->column_map : [];
        }

        $this->form->fill($initial);
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

        // Prefer the operator's edited column_map from form state; fall
        // back to a fresh auto-guess if nothing was set (e.g. file was
        // uploaded after Step 4 was last rendered).
        $columnMap = $state['column_map'] ?? null;
        if (! is_array($columnMap) || $columnMap === []) {
            $columnMap = self::guessColumnMap($importerClass, $headers);
        }
        /** @var array<string, string|null> $columnMap */
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

        // Persist the mapping as a reusable profile if the operator asked
        // for it — and stamp `last_used_at` / `use_count` on a profile we
        // started FROM, so the dropdown sort surfaces recent picks first.
        $savedProfile = $this->maybeSaveProfile($state, $type, $columnMap);
        $this->maybeMarkStartingProfileUsed($state, $savedProfile);

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

        // Surface the Import ID on the page so the "Download failed rows"
        // header action becomes visible once the batch has produced any
        // failure records. The action polls the model on click; we don't
        // need to know failure count synchronously at dispatch time.
        $this->lastImportId = (int) $importId;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* RFQ §3.1.3 — "downloadable CSV error report" header action */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Stream `failed_import_rows` for the wizard's most recent batch as a
     * UTF-8 CSV with the original row data + the validation error message.
     *
     * Security: gated on `static::canAccess()` and on the Import row's
     * `user_id` matching the current user — operators cannot probe other
     * users' failed-row sets by guessing IDs. Streamed (not buffered) so
     * a 50 000-row failure dump doesn't sit in memory.
     */
    public function downloadFailedRows(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->lastImportId === null, 404, 'No recent import to download failures for.');

        /** @var Import|null $import */
        $import = Import::query()->find($this->lastImportId);
        abort_if($import === null, 404, 'Import not found.');

        $user = auth()->user();
        abort_if($user === null || (int) $import->user_id !== (int) $user->getKey(), 403);

        $filename = sprintf('failed_import_rows_%d_%s.csv', $this->lastImportId, now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($import): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM for Excel compatibility on Windows / Maltese accents.
            fwrite($out, "\xEF\xBB\xBF");

            $first = true;
            $import->failedRows()->chunk(500, function ($rows) use ($out, &$first): void {
                foreach ($rows as $row) {
                    $rawData = $row->getAttribute('data');
                    $data = is_array($rawData) ? $rawData : (array) json_decode((string) $rawData, true);
                    if ($first) {
                        fputcsv($out, array_merge(array_keys($data), ['_validation_error']));
                        $first = false;
                    }
                    fputcsv($out, array_merge(
                        array_map(
                            static fn ($v): string => is_scalar($v) ? (string) $v : (string) json_encode($v),
                            $data,
                        ),
                        [(string) $row->getAttribute('validation_error')],
                    ));
                }
            });

            if ($first) {
                // No failed rows yet — emit a single header row so the file is well-formed.
                fputcsv($out, ['_no_failed_rows']);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build the `columnMap` array Filament's import jobs expect:
     * keys are Importer column names, values are the matching Excel
     * header strings (or null if not present).
     *
     * Cascading match strategy (per Importer field):
     *   1. exact name / label / `->guess()` alias (case-insensitive, trimmed);
     *   2. {@see SYNONYMS} table lookup;
     *   3. Levenshtein distance ≤ 3 fuzzy match against the field name.
     *
     * @param class-string<Importer> $importerClass
     * @param array<int, string> $excelHeaders
     * @param array<string, array<int, string>> $extraSynonyms per-profile aliases
     * @return array<string, string|null>
     */
    public static function guessColumnMap(string $importerClass, array $excelHeaders, array $extraSynonyms = []): array
    {
        $lowerExcel = [];
        foreach ($excelHeaders as $h) {
            $lowerExcel[mb_strtolower(trim($h))] = $h;
        }

        $map = [];
        foreach ($importerClass::getColumns() as $column) {
            $map[$column->getName()] = self::guessSingleColumn($column, $lowerExcel, $extraSynonyms);
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

    /**
     * @return array<int, FilamentAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            FilamentAction::make('downloadFailedRows')
                ->label('Download failed rows (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn (): bool => $this->lastImportId !== null)
                ->action(fn () => $this->downloadFailedRows()),
        ];
    }

    /**
     * Resolve `?profile=N` (or `$starting_profile_id`) into an actual
     * {@see ImportProfile} the current user is allowed to see. Returns
     * null if the id is missing, malformed, soft-deleted, or out of the
     * user's repository.
     */
    protected function resolveProfileFromQuery(): ?ImportProfile
    {
        $raw = $this->profileQuery;
        if ($raw === null || $raw === '' || ! ctype_digit((string) $raw)) {
            return null;
        }

        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        return ImportProfile::query()
            ->accessibleBy($user)
            ->whereKey((int) $raw)
            ->first();
    }

    /**
     * Persist the operator's column_map as a reusable {@see ImportProfile}
     * if they ticked "Save as profile" on step 5. Returns the saved profile
     * (or null if nothing was saved) so callers can correlate it with
     * `starting_profile_id` for markUsed bookkeeping.
     *
     * @param array<string, mixed> $state
     * @param array<string, string|null> $columnMap
     */
    protected function maybeSaveProfile(array $state, string $type, array $columnMap): ?ImportProfile
    {
        if (! ($state['save_as_profile'] ?? false)) {
            return null;
        }
        $name = trim((string) ($state['save_as_profile_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        /** @var User $user */
        try {
            $profile = ImportProfile::query()->create([
                'user_id' => (int) $user->getKey(),
                'repository_id' => $user->default_repository_id,
                'name' => mb_substr($name, 0, 191),
                'description' => null,
                'import_type' => $type,
                'column_map' => $columnMap,
                'synonyms' => null,
                'is_shared' => (bool) ($state['save_as_profile_shared'] ?? false),
            ]);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not save profile')
                ->body($e->getMessage())
                ->warning()
                ->send();

            return null;
        }

        Notification::make()
            ->title('Profile saved')
            ->body(sprintf('"%s" is available next time you import %s.', $profile->name, $type))
            ->success()
            ->send();

        return $profile;
    }

    /**
     * If the operator started from a saved profile, bump its `last_used_at`
     * + `use_count` so the dropdown in Step 1 sorts the most-recent picks
     * to the top.
     *
     * @param array<string, mixed> $state
     */
    protected function maybeMarkStartingProfileUsed(array $state, ?ImportProfile $newProfile): void
    {
        $startingId = $state['starting_profile_id'] ?? null;
        if ($startingId === null || $startingId === '' || ! ctype_digit((string) $startingId)) {
            return;
        }
        // Don't double-count: if we just created a NEW profile, we already
        // initialized its use_count at 0 and it's not the same record.
        $startingProfile = ImportProfile::query()->find((int) $startingId);
        if ($startingProfile === null) {
            return;
        }
        if ($newProfile !== null && (int) $newProfile->getKey() === (int) $startingProfile->getKey()) {
            return;
        }
        $startingProfile->markUsed();
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
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        // Reset profile + map when the operator switches entity:
                        // a profile is type-specific, so the previous choice is
                        // no longer valid for the new type.
                        $set('starting_profile_id', null);
                        $set('column_map', []);
                    }),

                Select::make('starting_profile_id')
                    ->label('Start from a saved profile')
                    ->helperText('Optional — pick a previously-saved column mapping. Leave empty to use auto-guess.')
                    ->placeholder('— Start from scratch (use auto-guess) —')
                    ->options(fn (Get $get): array => self::profileOptionsFor((string) ($get('import_type') ?? '')))
                    ->visible(fn (Get $get): bool => filled($get('import_type')) && count(self::profileOptionsFor((string) $get('import_type'))) > 0)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (! is_string($state) && ! is_int($state)) {
                            $set('column_map', []);

                            return;
                        }
                        if (! ctype_digit((string) $state)) {
                            $set('column_map', []);

                            return;
                        }
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }
                        /** @var User $user */
                        $profile = ImportProfile::query()
                            ->accessibleBy($user)
                            ->whereKey((int) $state)
                            ->first();
                        if ($profile === null) {
                            return;
                        }
                        $set('import_type', $profile->import_type);
                        $set('column_map', is_array($profile->column_map) ? $profile->column_map : []);
                    }),
            ]);
    }

    /**
     * List the profiles the current user can see for a given import_type,
     * sorted by recency (last_used_at desc, then created_at desc). Returns
     * a [id => label] map ready to feed a Select.
     *
     * @return array<string, string>
     */
    protected static function profileOptionsFor(string $importType): array
    {
        if (! array_key_exists($importType, self::IMPORTERS)) {
            return [];
        }
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        /** @var User $user */
        return ImportProfile::query()
            ->accessibleBy($user)
            ->ofType($importType)
            ->orderByRaw('last_used_at IS NULL') // non-null first
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name', 'use_count'])
            ->mapWithKeys(fn (ImportProfile $p): array => [
                (string) $p->getKey() => $p->use_count > 0
                    ? sprintf('%s (used %dx)', $p->name, $p->use_count)
                    : $p->name,
            ])
            ->all();
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
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        // When a file is uploaded (or replaced), seed the
                        // column_map from auto-guess UNLESS the operator
                        // already loaded one from a saved profile.
                        $existing = $get('column_map');
                        if (is_array($existing) && $existing !== []) {
                            return;
                        }
                        $type = (string) ($get('import_type') ?? '');
                        if (! array_key_exists($type, self::IMPORTERS)) {
                            return;
                        }
                        $info = $this->parseFilePreview($get('file'), (int) ($get('sheet') ?? 0));
                        if ($info === null) {
                            return;
                        }
                        $set('column_map', self::guessColumnMap(self::IMPORTERS[$type], $info['headers']));
                    }),

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
                    })
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        // Sheet change → re-guess unless a profile is in use.
                        $existing = $get('column_map');
                        if (is_array($existing) && $existing !== []) {
                            return;
                        }
                        $type = (string) ($get('import_type') ?? '');
                        if (! array_key_exists($type, self::IMPORTERS)) {
                            return;
                        }
                        $info = $this->parseFilePreview($get('file'), (int) ($get('sheet') ?? 0));
                        if ($info === null) {
                            return;
                        }
                        $set('column_map', self::guessColumnMap(self::IMPORTERS[$type], $info['headers']));
                    }),
            ]);
    }

    /* ──── Step 4: preview ───────────────────────────────────────── */

    protected function stepPreview(): Step
    {
        return Step::make('Preview & map columns')
            ->description('Spot-check the first 10 rows and tune the column mapping if needed.')
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
                    })
                    ->columnSpanFull(),

                // Editable column mapping. Each importer field gets its own
                // Select whose options are the detected Excel headers; the
                // default value comes from `column_map.<field>` which the
                // Step-3 file upload populates via guessColumnMap(). The
                // operator can override any cell to fix a wrong guess or
                // to skip a column entirely (--- skip --- option).
                Grid::make(1)
                    ->columnSpanFull()
                    ->schema(fn (Get $get): array => self::buildMappingEditor($get))
                    ->visible(function (Get $get): bool {
                        $type = (string) ($get('import_type') ?? '');
                        if (! array_key_exists($type, self::IMPORTERS)) {
                            return false;
                        }

                        return $this->parseFilePreview($get('file'), (int) ($get('sheet') ?? 0)) !== null;
                    }),

                Placeholder::make('missing_required_columns')
                    ->hiddenLabel()
                    ->content(function (Get $get): HtmlString {
                        $type = (string) ($get('import_type') ?? '');
                        if (! array_key_exists($type, self::IMPORTERS)) {
                            return new HtmlString('');
                        }
                        $columnMap = $get('column_map');
                        if (! is_array($columnMap)) {
                            $columnMap = [];
                        }
                        /** @var array<string, string|null> $columnMap */
                        $missing = self::findMissingRequiredColumns(self::IMPORTERS[$type], $columnMap);
                        if (count($missing) === 0) {
                            return new HtmlString(
                                '<p class="text-sm font-medium text-success-600">'
                                . 'All required columns are mapped — you can continue.</p>'
                            );
                        }

                        return new HtmlString(
                            '<p class="text-sm font-medium text-danger-600">'
                            . 'Missing required columns: <code>'
                            . e(implode(', ', $missing))
                            . '</code>. Pick the right Excel header above before you continue.</p>'
                        );
                    })
                    ->columnSpanFull(),
            ])
            ->afterValidation(function (Get $get): void {
                // Block "Next" while any required field is unmapped. We re-run
                // the same predicate the live placeholder shows so the wizard
                // step actually halts (a danger Notification is shown).
                $type = (string) ($get('import_type') ?? '');
                if (! array_key_exists($type, self::IMPORTERS)) {
                    return;
                }
                $columnMap = $get('column_map');
                if (! is_array($columnMap)) {
                    $columnMap = [];
                }
                /** @var array<string, string|null> $columnMap */
                $missing = self::findMissingRequiredColumns(self::IMPORTERS[$type], $columnMap);
                if (count($missing) === 0) {
                    return;
                }
                Notification::make()
                    ->title('Missing required columns')
                    ->body('Map: ' . implode(', ', $missing) . ' before you continue.')
                    ->danger()
                    ->send();

                throw new Halt;
            });
    }

    /**
     * Build the per-field Select rows for Step 4's mapping editor. Returns
     * an array of {@see Select} components, one per importer column,
     * scoped under the `column_map.<field>` state path.
     *
     * The Select options are ALL Excel headers detected in the uploaded
     * file (preserving original casing for display), plus a null "skip"
     * option. We use Filament's `placeholder` for the empty value rather
     * than a synthetic value because that survives the dehydrate /
     * rehydrate cycle without leaking a magic-string sentinel into the
     * persisted `column_map`.
     *
     * @return array<int, Component>
     */
    protected static function buildMappingEditor(Get $get): array
    {
        $type = (string) ($get('import_type') ?? '');
        if (! array_key_exists($type, self::IMPORTERS)) {
            return [];
        }
        $file = $get('file');
        $sheet = (int) ($get('sheet') ?? 0);
        $info = self::staticParseFilePreview($file, $sheet);
        if ($info === null) {
            return [];
        }

        /** @var class-string<Importer> $importerClass */
        $importerClass = self::IMPORTERS[$type];

        // Header → header map: the Select stores the original-cased header
        // string (which is what the import jobs use) — but using the
        // original as both key and value keeps the Select dropdown sane.
        $options = [];
        foreach ($info['headers'] as $h) {
            $original = (string) $h;
            if ($original === '') {
                continue;
            }
            $options[$original] = $original;
        }

        $rows = [];
        foreach ($importerClass::getColumns() as $column) {
            $fieldName = $column->getName();
            $required = $column->isMappingRequired();
            $label = (string) ($column->getLabel() ?? $fieldName);

            $select = Select::make('column_map.' . $fieldName)
                ->label($label . ($required ? ' *' : ''))
                ->helperText(sprintf(
                    'Importer field: %s%s',
                    $fieldName,
                    $required ? ' — required' : '',
                ))
                ->options($options)
                ->placeholder('— (skip this column) —')
                ->searchable()
                ->live();

            $rows[] = $select->columnSpanFull();
        }

        return $rows;
    }

    /**
     * Static wrapper around {@see parseFilePreview()} so static
     * schema-builder closures can share the parser without binding `$this`.
     *
     * @return array{headers:array<int, string>, rows:array<int, array<int, mixed>>, totalRows:int}|null
     */
    protected static function staticParseFilePreview(mixed $file, int $sheet): ?array
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

                Checkbox::make('save_as_profile')
                    ->label('Save this column mapping as a reusable profile')
                    ->helperText('Next time you import a spreadsheet with the same layout, pick the profile in step 1 to skip the column-by-column work.')
                    ->live(),

                TextInput::make('save_as_profile_name')
                    ->label('Profile name')
                    ->placeholder('e.g. NRA legacy Excel — Documents')
                    ->maxLength(191)
                    ->visible(fn (Get $get): bool => (bool) $get('save_as_profile'))
                    ->required(fn (Get $get): bool => (bool) $get('save_as_profile'))
                    ->columnSpanFull(),

                Checkbox::make('save_as_profile_shared')
                    ->label('Share this profile with the rest of my repository')
                    ->visible(fn (Get $get): bool => (bool) $get('save_as_profile')),
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
     * Match a single importer column against the uploaded headers using
     * the three-tier cascade: exact → synonyms → fuzzy.
     *
     * @param array<string, string> $lowerExcel lowercase-trimmed-header → original-header
     * @param array<string, array<int, string>> $extraSynonyms per-profile additions to {@see SYNONYMS}
     */
    protected static function guessSingleColumn(
        ImportColumn $column,
        array $lowerExcel,
        array $extraSynonyms = [],
    ): ?string {
        // Tier 1: exact candidates (name, label, ->guess() aliases).
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

        // Tier 2: synonyms table — for each Excel header, look up its
        // synonyms map and if this column's name is among them, claim it.
        $fieldName = $column->getName();
        $synonyms = $extraSynonyms + self::SYNONYMS;
        foreach ($lowerExcel as $lowerHeader => $originalHeader) {
            $synonymTargets = $synonyms[$lowerHeader] ?? null;
            if (! is_array($synonymTargets)) {
                continue;
            }
            if (in_array($fieldName, $synonymTargets, true)) {
                return $originalHeader;
            }
        }

        // Tier 3: Levenshtein distance ≤ 3 against the field name. Very
        // short field names skew the threshold (a 4-char name + 3 typo
        // tolerance is gibberish), so we bias the cutoff by length.
        $bestHeader = null;
        $bestDistance = PHP_INT_MAX;
        $maxDistance = max(1, min(3, (int) floor(mb_strlen($fieldName) / 3)));
        $targetSlug = self::slugify($fieldName);
        foreach ($lowerExcel as $originalHeader) {
            $headerSlug = self::slugify($originalHeader);
            if ($headerSlug === '') {
                continue;
            }
            $distance = levenshtein($targetSlug, $headerSlug);
            if ($distance < $bestDistance && $distance <= $maxDistance) {
                $bestDistance = $distance;
                $bestHeader = $originalHeader;
            }
        }

        return $bestHeader;
    }

    /**
     * Normalize a header for fuzzy comparison: lowercase, strip everything
     * non-alphanumeric, collapse. E.g. "Creator Name (en)" → "creatorname".
     */
    protected static function slugify(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return (string) preg_replace('/[^a-z0-9]+/i', '', $lower);
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
