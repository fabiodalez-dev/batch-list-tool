<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;

/**
 * Centralised foreign-key resolver for the v2 Bulk Import (RFQ §3.1.3).
 *
 * The headline UX feature of the Filament Excel Import is that operators map
 * each spreadsheet column individually and the system understands FKs by
 * *name* — not just by surrogate id. This class is where that "by name"
 * intelligence lives.
 *
 * Resolution strategies (per entity) are the same as the one shipped in
 * App\Console\Commands\LinkCreatorTextToAuthorities — we reuse the patterns
 * intentionally so the live link command and the import command behave
 * identically. In particular:
 *
 *  - F-001: never fuzzy-match on tokens shorter than 4 characters (Maltese
 *    surnames like "Mai" would collide with hundreds of unrelated rows).
 *  - F-002: callers expected to memoise; the resolver itself does not query
 *    in a loop without need.
 *  - F-009: when more than one Authority is found on a surname, the resolver
 *    returns an `ambiguous_count` marker — callers MUST NOT auto-assign.
 *
 * All methods are static; the resolver has no state of its own beyond a
 * per-request in-memory memo cache (`self::$memo`). The cache is flushed
 * implicitly between import jobs because the class is reloaded with each
 * worker process.
 */
final class EntityResolver
{
    /**
     * Per-request memoisation cache.
     *
     * Key shape: `entity:strategy:normalised_token` → result array.
     * Cleared explicitly only in tests (see {@see self::flushMemo()}).
     *
     * @var array<string, array<string, mixed>|null>
     */
    private static array $memo = [];

    /**
     * Resolve an Authority by (1) identifier, (2) surname+name pair,
     * (3) surname exact (case-insensitive), (4) surname LIKE (≥4 chars).
     *
     * Returns one of:
     *  - `['authority_id' => int, 'method' => string]` — single confident match.
     *  - `['ambiguous_count' => int, 'candidates' => int[]]` — F-009: more than
     *     one Authority share the same surname, caller MUST NOT pick one.
     *  - `null` — no match at all.
     *
     * @return array{authority_id:int,method:string}|array{ambiguous_count:int,candidates:array<int,int>}|null
     */
    public static function resolveAuthority(
        ?string $identifier,
        ?string $surname = null,
        ?string $name = null,
    ): ?array {
        $identifier = self::normaliseString($identifier);
        $surname = self::normaliseString($surname);
        $name = self::normaliseString($name);

        // Strategy 1 — exact match on identifier (the canonical R-code from the
        // POC: "R1", "R12", "R110"). This is the strongest signal: every
        // Authority has a unique identifier, so a hit here is final.
        if ($identifier !== null) {
            $key = "authority:identifier:{$identifier}";
            if (! array_key_exists($key, self::$memo)) {
                $id = Authority::query()
                    ->where('identifier', $identifier)
                    ->value('id');
                self::$memo[$key] = $id !== null
                    ? ['authority_id' => (int) $id, 'method' => 'identifier']
                    : null;
            }
            if (self::$memo[$key] !== null) {
                return self::$memo[$key];
            }
        }

        // The "Creator" column in Batch_List_Sample is free-text catalogator,
        // sometimes "Name Surname", sometimes "Surname, Name", sometimes just
        // "Surname". We split on whitespace and try the last word first
        // (matches Italian/Maltese convention).
        if ($surname === null && $name !== null) {
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) > 0) {
                $surname = mb_strtolower(trim((string) end($parts)));
            }
        }

        if ($surname === null) {
            return null;
        }

        // Strategy 2 — surname + given-name pair. Most discriminating after
        // the identifier; collapses many ambiguous surname-only matches.
        if ($name !== null) {
            $key = "authority:surname_given:{$surname}|{$name}";
            if (! array_key_exists($key, self::$memo)) {
                $rows = Authority::query()
                    ->whereRaw('LOWER(surname) = ?', [mb_strtolower($surname)])
                    ->whereRaw('LOWER(given_names) = ?', [mb_strtolower($name)])
                    ->limit(2)
                    ->pluck('id')
                    ->all();
                if (count($rows) === 1) {
                    self::$memo[$key] = ['authority_id' => (int) $rows[0], 'method' => 'surname_given'];
                } else {
                    self::$memo[$key] = null;
                }
            }
            if (self::$memo[$key] !== null) {
                return self::$memo[$key];
            }
        }

        // Strategy 3 — surname exact (case-insensitive). Most rows in the
        // legacy POC use only the surname; this is where ambiguous-surname
        // collisions appear and F-009 kicks in.
        $key = "authority:surname_exact:{$surname}";
        if (! array_key_exists($key, self::$memo)) {
            $rows = Authority::query()
                ->whereRaw('LOWER(surname) = ?', [mb_strtolower($surname)])
                ->limit(20)
                ->pluck('id')
                ->all();
            if (count($rows) === 1) {
                self::$memo[$key] = ['authority_id' => (int) $rows[0], 'method' => 'surname_exact'];
            } elseif (count($rows) > 1) {
                // F-009 — DO NOT auto-assign. Return the candidates so the
                // caller can persist them in `document.extra.ambiguous_candidates`
                // for an operator to resolve manually.
                self::$memo[$key] = [
                    'ambiguous_count' => count($rows),
                    'candidates' => array_map('intval', $rows),
                ];
            } else {
                self::$memo[$key] = null;
            }
        }
        if (self::$memo[$key] !== null) {
            return self::$memo[$key];
        }

        // Strategy 4 — surname fuzzy LIKE. F-001: refuse on short tokens
        // because anything <4 chars matches half the table on a generous
        // surname distribution (e.g. "Mai" matches "Maillé", "Maibach",
        // "Maita", …). Yields too many false positives to be useful.
        if (mb_strlen($surname) < 4) {
            return null;
        }
        $key = "authority:surname_fuzzy:{$surname}";
        if (! array_key_exists($key, self::$memo)) {
            $rows = Authority::query()
                ->where('surname', 'like', '%' . $surname . '%')
                ->orderByRaw('LENGTH(surname) ASC')
                ->limit(2)
                ->pluck('id')
                ->all();
            if (count($rows) === 1) {
                self::$memo[$key] = ['authority_id' => (int) $rows[0], 'method' => 'surname_fuzzy'];
            } elseif (count($rows) > 1) {
                self::$memo[$key] = [
                    'ambiguous_count' => count($rows),
                    'candidates' => array_map('intval', $rows),
                ];
            } else {
                self::$memo[$key] = null;
            }
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a Series by either:
     *   - exact `code` ("R", "REG", "RWL", "O"), or
     *   - the legacy POC formatting "CODE: Title…" (split on ":"), or
     *   - exact `title` (case-insensitive) as a last fallback.
     *
     * Returns `['series_id' => int]` on a unique match, `null` otherwise.
     * Ambiguity on series is *not* expected because `series.code` is `UNIQUE`
     * and titles are short and distinct; we therefore short-circuit on the
     * first match and never produce an `ambiguous_count`.
     *
     * @return array{series_id:int}|null
     */
    public static function resolveSeries(?string $codeOrFullText): ?array
    {
        $text = self::normaliseString($codeOrFullText);
        if ($text === null) {
            return null;
        }

        // Batch_List_Sample.xlsx uses the format "REG: Registers Private
        // Practice" in the Series column — the part before ":" is the
        // canonical code in the database. We try that path first.
        $code = $text;
        if (str_contains($text, ':')) {
            $code = trim((string) explode(':', $text, 2)[0]);
        }
        // Series codes are at most 16 chars in the schema.
        $code = mb_substr($code, 0, 16);

        if ($code !== '') {
            $key = "series:code:{$code}";
            if (! array_key_exists($key, self::$memo)) {
                $id = Series::query()
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
                    ->value('id');
                self::$memo[$key] = $id !== null ? ['series_id' => (int) $id] : null;
            }
            if (self::$memo[$key] !== null) {
                return self::$memo[$key];
            }
        }

        // Fallback: full text exact match on `title`. Useful when an operator
        // pastes "Registers Private Practice" instead of "REG: Registers …".
        $key = "series:title:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = Series::query()
                ->whereRaw('LOWER(title) = ?', [mb_strtolower($text)])
                ->value('id');
            self::$memo[$key] = $id !== null ? ['series_id' => (int) $id] : null;
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a Batch by its `batch_number`. Enforces RFQ App.1 #1: numbers
     * 33, 34 and 36 are reserved and cannot be allocated to new records —
     * this is also enforced by a MySQL CHECK constraint, but rejecting
     * client-side gives the operator a clean error message in the row
     * report instead of a 1452-style SQLSTATE leak.
     *
     * The lookup ignores the global RepositoryScope so import preview can
     * resolve cross-tenant matches; the caller is expected to validate
     * tenancy further upstream (`fillRecordUsing` runs INSIDE the global
     * scope, so the security boundary is preserved).
     *
     * Task 8 (B5) — accession-import integrity. When `$create` is true the
     * resolver becomes dedup-OR-CREATE: a batch that does not yet exist is
     * inserted (respecting A1.1 forbidden numbers, which are rejected BEFORE
     * any write) so a single import run can stand up the Batch + Box graph an
     * incoming document references. Forbidden numbers can never be created —
     * the `['forbidden' => N]` short-circuit happens above the create branch.
     * The `type` is auto-derived from the number (1..29 → MAIN_COLLECTION,
     * 30+ → NOTARY_ACCESSION) to mirror BatchImporter's convention.
     *
     * @return array{batch_id:int,batch_number:int}|array{forbidden:int}|null
     */
    public static function resolveBatch(
        ?int $batchNumber,
        ?int $repositoryId = null,
        bool $create = false,
    ): ?array {
        if ($batchNumber === null) {
            return null;
        }

        if (in_array($batchNumber, Batch::FORBIDDEN_NUMBERS, true)) {
            // Signalled separately from "not found" so the importer can emit
            // a specific human-readable error: "Batch N is reserved". This is
            // evaluated BEFORE the create branch, so a forbidden batch is
            // never inserted (B5 + A1.1).
            return ['forbidden' => $batchNumber];
        }

        $key = "batch:number:{$batchNumber}:" . ($repositoryId ?? '*');
        if (! array_key_exists($key, self::$memo)) {
            $q = Batch::query()->withoutGlobalScope(RepositoryScope::class)
                ->where('batch_number', $batchNumber);
            if ($repositoryId !== null) {
                $q->where('repository_id', $repositoryId);
            }
            $id = $q->value('id');
            self::$memo[$key] = $id !== null
                ? ['batch_id' => (int) $id, 'batch_number' => $batchNumber]
                : null;
        }

        if (self::$memo[$key] === null && $create) {
            // Dedup-OR-CREATE (B5): firstOrCreate to stay idempotent under
            // the unique batch_number even if two rows in the same run point
            // at the same new batch. repository_id is left for the
            // BelongsToRepository creating-hook to default from the acting
            // user when not supplied.
            $attrs = ['batch_number' => $batchNumber];
            if ($repositoryId !== null) {
                $attrs['repository_id'] = $repositoryId;
            }
            $batch = Batch::query()->withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
                $attrs,
                [
                    'type' => $batchNumber >= 30 ? 'NOTARY_ACCESSION' : 'MAIN_COLLECTION',
                    'is_active' => true,
                ],
            );
            self::$memo[$key] = ['batch_id' => (int) $batch->id, 'batch_number' => $batchNumber];
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a Box by (1) barcode (unique in the schema), or (2) the pair
     * (batch_id, box_number). Returns `['box_id' => int, 'batch_id' => int]`
     * on a unique match or `null` otherwise. Box scoping is derived via the
     * parent Batch so we don't have to pass `repository_id` here — the global
     * scope on `Box` already excludes boxes the user can't see.
     *
     * `batch_id` is always echoed back in the result so the caller can
     * validate document/box batch consistency (Task 8, B5) without a second
     * query.
     *
     * Task 8 (B5) — when `$create` is true and the box is resolved by the
     * (batch_id, box_number) pair, a missing box is inserted into the resolved
     * batch. A barcode-only lookup never creates: a barcode names a specific
     * existing physical box, so a miss is a genuine "unknown box" and must NOT
     * fabricate one. Legacy box types (MAV / STVC) get `is_legacy = true`,
     * mirroring BoxImporter so the A1.4 creating-guard accepts them.
     *
     * @return array{box_id:int,batch_id:int}|null
     */
    public static function resolveBox(
        ?string $barcode,
        ?int $batchId = null,
        ?string $boxNumber = null,
        bool $create = false,
        ?string $boxType = null,
    ): ?array {
        $barcode = self::normaliseString($barcode);

        if ($barcode !== null) {
            $key = "box:barcode:{$barcode}";
            if (! array_key_exists($key, self::$memo)) {
                // BUG-09: barcodes are globally unique physical labels — they
                // must be resolved without any repository/tenant scope, consistent
                // with AccessionRowImporter's own withoutGlobalScope barcode check.
                // The caller is responsible for validating cross-repo correctness
                // after resolution (e.g. DocumentImporter::resolveCurrentBox does
                // a batch-id consistency check). Using the global scope here would
                // return null for a box that exists in another tenant scope,
                // causing silent null assignment on the document (data loss).
                $row = Box::query()
                    ->withoutGlobalScopes()
                    ->where('barcode', $barcode)
                    ->first(['id', 'batch_id']);
                self::$memo[$key] = $row !== null
                    ? ['box_id' => (int) $row->id, 'batch_id' => (int) $row->batch_id]
                    : null;
            }
            if (self::$memo[$key] !== null) {
                return self::$memo[$key];
            }
        }

        $boxNumber = self::normaliseString($boxNumber);
        if ($batchId !== null && $boxNumber !== null) {
            $key = "box:batch_number:{$batchId}|{$boxNumber}";
            if (! array_key_exists($key, self::$memo)) {
                $row = Box::query()
                    ->where('batch_id', $batchId)
                    ->where('box_number', $boxNumber)
                    ->first(['id', 'batch_id']);
                self::$memo[$key] = $row !== null
                    ? ['box_id' => (int) $row->id, 'batch_id' => (int) $row->batch_id]
                    : null;
            }

            if (self::$memo[$key] === null && $create) {
                // Dedup-OR-CREATE (B5): create the box inside the resolved
                // batch. firstOrCreate on (batch_id, box_number) keeps two
                // rows in the same run that reference the same new box
                // idempotent. Legacy types are flagged is_legacy so the
                // Box A1.4 creating-guard accepts the historical record.
                $type = $boxType !== null ? strtoupper(trim($boxType)) : 'RAS';
                $isLegacy = in_array($type, Box::LEGACY_TYPES, true);
                $box = Box::query()->firstOrCreate(
                    ['batch_id' => $batchId, 'box_number' => $boxNumber],
                    ['box_type' => $type, 'is_legacy' => $isLegacy],
                );
                self::$memo[$key] = ['box_id' => (int) $box->id, 'batch_id' => (int) $box->batch_id];
            }

            return self::$memo[$key];
        }

        return null;
    }

    /**
     * Resolve a Repository by its `code` (the tenant key, e.g. "NRA") or
     * `name`. Used by Authority/Series importers when the operator wants to
     * stamp imported rows into a specific tenant via an `additionalFormComponents`
     * Select.
     *
     * @return array{repository_id:int}|null
     */
    public static function resolveRepository(?string $codeOrName): ?array
    {
        $text = self::normaliseString($codeOrName);
        if ($text === null) {
            return null;
        }

        $key = "repository:code_or_name:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = Repository::query()
                ->where(function ($q) use ($text) {
                    $q->whereRaw('LOWER(code) = ?', [mb_strtolower($text)])
                        ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($text)]);
                })
                ->value('id');
            self::$memo[$key] = $id !== null ? ['repository_id' => (int) $id] : null;
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a DocumentType by (1) exact `identifier` match (case-insensitive),
     * then (2) exact `name` match (case-insensitive). Returns `['document_type_id' => int]`
     * on a unique match or `null` otherwise.
     *
     * This is additive — documents.document_type stays free-text, so this
     * resolver is for future import enrichment only and does NOT modify
     * existing importers.
     *
     * @return array{document_type_id:int}|null
     */
    public static function resolveDocumentType(?string $identifierOrName): ?array
    {
        $text = self::normaliseString($identifierOrName);
        if ($text === null) {
            return null;
        }

        // Strategy 1 — exact identifier match (identifier is unique when non-null).
        $key = "document_type:identifier:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = DocumentType::query()
                ->whereRaw('LOWER(identifier) = ?', [mb_strtolower($text)])
                ->value('id');
            self::$memo[$key] = $id !== null ? ['document_type_id' => (int) $id] : null;
        }
        if (self::$memo[$key] !== null) {
            return self::$memo[$key];
        }

        // Strategy 2 — exact name match (case-insensitive). Name is unique per
        // the DB constraint, so the first hit is deterministic.
        $key = "document_type:name:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = DocumentType::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($text)])
                ->value('id');
            self::$memo[$key] = $id !== null ? ['document_type_id' => (int) $id] : null;
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a Practice by (1) exact `identifier` match (case-insensitive),
     * then (2) exact `name` match (case-insensitive). Mirrors resolveDocumentType().
     *
     * D4 (Feedback1 Wave D) — the optional `identifier` field on Practice is the
     * primary import key; falls back to name for legacy data.
     *
     * Returns `['practice_id' => int]` on a unique match or `null` otherwise.
     *
     * @return array{practice_id:int}|null
     */
    public static function resolvePractice(?string $identifierOrName): ?array
    {
        $text = self::normaliseString($identifierOrName);
        if ($text === null) {
            return null;
        }

        // Strategy 1 — exact identifier match (unique when non-null).
        $key = "practice:identifier:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = Practice::query()
                ->whereRaw('LOWER(identifier) = ?', [mb_strtolower($text)])
                ->value('id');
            self::$memo[$key] = $id !== null ? ['practice_id' => (int) $id] : null;
        }
        if (self::$memo[$key] !== null) {
            return self::$memo[$key];
        }

        // Strategy 2 — exact name match (case-insensitive).
        $key = "practice:name:{$text}";
        if (! array_key_exists($key, self::$memo)) {
            $id = Practice::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($text)])
                ->value('id');
            self::$memo[$key] = $id !== null ? ['practice_id' => (int) $id] : null;
        }

        return self::$memo[$key];
    }

    /**
     * Resolve a Location by its `code` (case-insensitive) within the given
     * repository (or globally when $repositoryId is null). The auto-generated
     * `code` field is the import key for locations, exactly as described in
     * decisions D3/D8 (code = 'Identifier').
     *
     * Returns `['location_id' => int]` on a unique match, `null` otherwise.
     * The caller is expected to throw a ValidationException if the code is
     * supplied but not found (unknown code is always an operator error).
     *
     * @return array{location_id:int}|null
     */
    public static function resolveLocation(?string $code, ?int $repositoryId = null): ?array
    {
        $text = self::normaliseString($code);
        if ($text === null) {
            return null;
        }

        $key = "location:code:{$text}:" . ($repositoryId ?? '*');
        if (! array_key_exists($key, self::$memo)) {
            $q = Location::query()
                ->withoutGlobalScopes()
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($text)]);
            if ($repositoryId !== null) {
                $q->where(function ($inner) use ($repositoryId): void {
                    $inner->where('repository_id', $repositoryId)
                        ->orWhereNull('repository_id');
                });
            }
            $id = $q->value('id');
            self::$memo[$key] = $id !== null ? ['location_id' => (int) $id] : null;
        }

        return self::$memo[$key];
    }

    /**
     * Flush the per-request memoisation cache. Tests call this between
     * scenarios to make sure stub data doesn't bleed across cases; the
     * production path never needs to call it (a fresh PHP process means a
     * fresh class).
     */
    public static function flushMemo(): void
    {
        self::$memo = [];
        // BUG-08 test cleanup: reset the AccessionRowImporter sequence counter
        // so successive test scenarios each restart the document-identifier
        // sequence at 1. This call is safe because AccessionRowImporter does NOT
        // use EntityResolver in its class body — it only calls EntityResolver
        // at runtime, so there is no circular-dependency issue.
        AccessionRowImporter::resetBoxRowSeq();
    }

    /**
     * Normalise a raw spreadsheet cell into a trimmed, non-empty string, or
     * `null` if it's effectively blank. Centralised here to keep the policy
     * for what "blank" means consistent across every resolver path: a cell
     * with only whitespace is treated identically to an empty cell.
     */
    private static function normaliseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = is_scalar($value) ? trim((string) $value) : '';

        return $string === '' ? null : $string;
    }
}
