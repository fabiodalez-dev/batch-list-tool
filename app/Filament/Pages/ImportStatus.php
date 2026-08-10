<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * A1 (Wave A) — Import Status page.
 *
 * Lists recent Filament import records from the `imports` table so operators
 * can see whether queued imports completed, how many rows succeeded, and how
 * many failed — without needing to wait for a notification or dig through logs.
 *
 * Columns surfaced:
 *   - File name
 *   - Importer class (short name)
 *   - Processed / Total rows
 *   - Successful rows
 *   - Failed rows  (computed = total − successful)
 *   - Completed at (or "Pending")
 *   - Link to the failed-rows CSV if any rows failed and the import is done
 *
 * Access: same gate as ImportWizard — admin / super_admin only.
 */
class ImportStatus extends Page
{
    protected string $view = 'filament.pages.import-status';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 31;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Import Status';

    protected static ?string $title = 'Import Status';

    protected static ?string $slug = 'import-status';

    /** Admin and super_admin only — mirrors ImportWizard. */
    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public static function canView(): bool
    {
        return static::canAccess();
    }

    /** Guard every mounted lifecycle call as well. */
    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Build the list of import records to display in the view.
     *
     * Returns up to 50 most-recent imports, newest first, with a pre-computed
     * `failed_rows` count and an optional signed download URL for the
     * failed-rows CSV.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getImports(): array
    {
        /** @var array<int,array<string,mixed>> $rows */
        $rows = Import::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(function (Import $import): array {
                $failedCount = $import->total_rows - $import->successful_rows;

                // An import that has been "Pending" (completed_at IS NULL) for
                // more than a few minutes almost always means the queue worker
                // is not running (QUEUE_CONNECTION=database needs `queue:work`).
                // Flag it so the operator understands why nothing appears to
                // happen instead of silently waiting (NAF Feedback-1 comment #3:
                // "it did not import at all and was not shown on the page").
                $isStalled = $import->completed_at === null
                    && $import->created_at !== null
                    && $import->created_at->lt(now()->subMinutes(5));

                // Filament registers the download route as a signed URL keyed
                // by the import id and an auth-guard parameter.  We generate
                // it on the server (signed) so it is ready to embed as a link.
                // The route only works for the logged-in user; if we cannot
                // generate it (e.g. route not yet registered in tests) we skip.
                $failedDownloadUrl = null;
                if ($import->completed_at !== null && $failedCount > 0) {
                    try {
                        // absolute: false — the vendor controller validates with
                        // hasValidSignature(absolute: false), so the signature must
                        // be computed over the RELATIVE url (an absolute-signed link
                        // fails validation with a 403). Mirrors ImportAction + ImportWizard.
                        $failedDownloadUrl = URL::signedRoute(
                            'filament.imports.failed-rows.download',
                            [
                                'authGuard' => 'web',
                                'import' => $import->getKey(),
                            ],
                            absolute: false,
                        );
                    } catch (\Throwable) {
                        // Route not registered (e.g. in unit tests) — silently skip.
                    }
                }

                // Client 2026-08-10 (Charlene): every failure must show WHY —
                // the errors CSV lives in the last column and scrolls off-screen,
                // so surface the reason(s) inline. Group the import's
                // failed_import_rows by their stored message so one line covers
                // any number of identical failures ("N × <reason>").
                $failureReasons = [];
                if ($failedCount > 0) {
                    $failureReasons = DB::table('failed_import_rows')
                        ->where('import_id', $import->getKey())
                        ->selectRaw('validation_error as reason, COUNT(*) as count')
                        ->groupBy('validation_error')
                        ->orderByDesc('count')
                        ->limit(20)
                        ->get()
                        ->map(function (object $r): array {
                            // Filament may store the error as a JSON map of
                            // field => [messages]; flatten it to a sentence.
                            // Plain-string reasons (e.g. the "Skipped — row
                            // already exists" note) pass through unchanged.
                            $reason = (string) ($r->reason ?? '');
                            $decoded = json_decode($reason, true);
                            if (is_array($decoded)) {
                                $reason = collect($decoded)->flatten()->implode(' ');
                            }

                            return [
                                'reason' => $reason !== '' ? $reason : 'Unknown error',
                                'count' => (int) $r->count,
                            ];
                        })
                        ->all();
                }

                // Short importer class name (strip namespace) for display.
                $importerShort = class_exists($import->importer)
                    ? new \ReflectionClass($import->importer)->getShortName()
                    : $import->importer;

                // Resolve the inputter name via user_id to avoid the
                // Authenticatable->name property access that PHPStan flags.
                $inputterName = null;
                if ($import->user_id !== null) {
                    $inputterName = User::query()
                        ->select('name')
                        ->find($import->user_id)
                        ?->name;
                }

                return [
                    'id' => $import->getKey(),
                    'file_name' => $import->file_name,
                    'importer' => $importerShort,
                    'processed_rows' => $import->processed_rows,
                    'total_rows' => $import->total_rows,
                    'successful_rows' => $import->successful_rows,
                    'failed_rows' => $failedCount,
                    'failure_reasons' => $failureReasons,
                    'completed_at' => $import->completed_at,
                    'is_stalled' => $isStalled,
                    'inputter' => $inputterName,
                    'failed_download' => $failedDownloadUrl,
                ];
            })
            ->toArray();

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    protected function getViewData(): array
    {
        return [
            'imports' => $this->getImports(),
        ];
    }
}
