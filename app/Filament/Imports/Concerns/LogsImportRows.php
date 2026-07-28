<?php

declare(strict_types=1);

namespace App\Filament\Imports\Concerns;

use App\Support\Audit\ImportAwareUserResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\AuditableObserver;

/**
 * Verbose, operator-facing logging for every import row + REAL error surfacing.
 *
 * The streaming importer (hayderhatem/filament-excel-import) masks any error
 * whose message contains "SQLSTATE" or is longer than 200 chars behind a
 * generic "generic_validation" message — so the operator never learns WHY a row
 * failed, and neither do we. This trait wraps saveRecord():
 *
 *   - logs every row attempt + outcome to the dedicated `import` channel
 *     (storage/logs/import-YYYY-MM-DD.log), including the FULL raw exception;
 *   - re-throws a SHORT, clear, human-readable message (no "SQLSTATE", < 200
 *     chars) so the failed-rows report the operator downloads states the real
 *     reason instead of the opaque generic one.
 */
trait LogsImportRows
{
    public function __invoke(array $data): void
    {
        // Excel numeric cells arrive as int/float on the streaming path; a plain
        // `string` validation rule then rejects them ("… must be a string") — the
        // exact reason 533 of the client's 678 authorities failed (their
        // alternative_identifier was a number like 511). Coerce every numeric
        // cell to a string up-front. Filament's boolean/numeric column casts
        // re-parse strings, so nothing else changes.
        foreach ($data as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $data[$key] = (string) $value;
            }
        }

        parent::__invoke($data);
    }

    public function saveRecord(): void
    {
        $short = class_basename(static::class);

        Log::channel('import')->debug("{$short}: saving row", $this->importLogContext());

        // Make the imported record auditable + attributed to the operator who
        // launched the import — so the resources' "Inputter" column is populated
        // instead of blank. Three things are needed, all scoped tightly to this
        // one save and reverted afterwards:
        //   1. the OwenIt observer must be attached (owen-it skips attaching it
        //      at boot in a console/queue process where audit.console is false);
        //   2. audit.console must be true at write time (owen-it's gate);
        //   3. the audit user resolver must return the import's user (the queue
        //      worker has no authenticated guard).
        // Guard keyed on the CONTAINER (not a process-static): owen-it registers
        // the observer at boot, and the app re-boots per queue:work run (and per
        // test), so the "already attached" flag must reset when the observer
        // registration does. A process-static would wrongly skip re-attaching
        // after a reboot, silently losing auditing again.
        $model = $this->record;
        if ($model !== null) {
            $guardKey = 'import.audit_observer.' . $model::class;
            if (! app()->bound($guardKey)) {
                $model::observe(AuditableObserver::class);
                app()->instance($guardKey, true);
            }
        }

        $previousConsole = config('audit.console');
        config(['audit.console' => true]);
        ImportAwareUserResolver::$importActor = $this->import->user;

        try {
            parent::saveRecord();
        } catch (\Throwable $e) {
            $human = self::humaniseImportError($e);

            Log::channel('import')->error("{$short}: ROW FAILED — {$human}", [
                ...$this->importLogContext(),
                'exception' => $e::class,
                'raw_message' => $e->getMessage(),
            ]);

            // Re-throw a clean, short message so the vendor importer does NOT
            // mask it as generic_validation — the operator sees the real reason.
            throw new RowImportFailedException($human);
        } finally {
            ImportAwareUserResolver::$importActor = null;
            config(['audit.console' => $previousConsole]);
        }

        Log::channel('import')->info("{$short}: row saved", [
            'importer' => static::class,
            'import_id' => $this->import->getKey(),
            'record_id' => $this->record?->getKey(),
        ]);
    }

    /**
     * Turn a raw DB/other exception into a short, clear, operator-facing reason
     * (no "SQLSTATE", under 200 chars) so it survives the vendor's masking.
     */
    public static function humaniseImportError(\Throwable $e): string
    {
        $raw = $e->getMessage();

        if ($e instanceof QueryException || stripos($raw, 'SQLSTATE') !== false) {
            // Unique key — MySQL ("Duplicate entry 'X' for key 'Y'") + SQLite
            // ("UNIQUE constraint failed: table.col").
            if (preg_match("/Duplicate entry '([^']*)' for key/i", $raw, $m)) {
                // Truncate the offending VALUE: a long duplicate (e.g. a 120-char
                // catalogue identifier) would push the whole message past the
                // vendor's 200-char mask threshold and get re-masked as generic.
                $value = self::shorten($m[1], 60);

                return "A record with this value already exists (duplicate '{$value}'). Delete or update the existing one, or remove the duplicate row.";
            }
            // Capture the FULL comma-separated column list — SQLite composite
            // indexes read "table.a, table.b"; the old regex stopped at the
            // first whitespace and dropped every column after the first, hiding
            // the operator-actionable one (e.g. "code" in (repository_id, code)).
            if (preg_match('/UNIQUE constraint failed:\s*([^(]+)/i', $raw, $m)) {
                $columns = self::shorten(trim($m[1]), 80);

                return "A record with this key already exists ({$columns}). Delete or update the existing one, or remove the duplicate row.";
            }
            // Missing required value — MySQL + SQLite ("NOT NULL constraint failed: table.col").
            if (preg_match("/Column '([^']+)' cannot be null/i", $raw, $m)
                || preg_match("/Field '([^']+)' doesn't have a default value/i", $raw, $m)
                || preg_match('/NOT NULL constraint failed:\s*([^\s(]+)/i', $raw, $m)) {
                return "A required value is missing for '{$m[1]}'. Fill that column and re-upload.";
            }
            if (preg_match("/Data too long for column '([^']+)'/i", $raw, $m)) {
                return "The value in '{$m[1]}' is too long for that field. Shorten it and re-upload.";
            }
            if (stripos($raw, 'foreign key constraint') !== false) {
                return 'This row references another record that does not exist. Import the parent record first.';
            }
            if (preg_match("/Incorrect (\w+) value: '([^']*)'/i", $raw, $m)) {
                return "The value '{$m[2]}' is not a valid {$m[1]} for its column.";
            }

            return 'The database rejected this row. See the import log for the exact SQL error.';
        }

        // Non-DB error: strip any trailing SQL dump and clamp the length.
        $clean = trim((string) preg_replace('/\(SQL:.*$/s', '', $raw));

        return mb_strlen($clean) > 180 ? mb_substr($clean, 0, 177) . '…' : $clean;
    }

    protected function importLogContext(): array
    {
        return [
            'importer' => static::class,
            'import_id' => $this->import->getKey(),
            'row' => $this->data,
        ];
    }

    /**
     * Ellipsize an interpolated value so a humanised message stays well under
     * the vendor's 200-char generic-validation mask threshold.
     */
    private static function shorten(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }
}
