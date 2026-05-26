<?php

namespace App\Console\Commands;

use App\Models\Authority;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Match the legacy free-text Creator field on each Document against the
 * Authorities table, populating the document_authority pivot.
 *
 * Strategy (best-effort):
 *   1. Split the legacy_creator_text on ";" — POC allowed multiple creators per document
 *   2. For each token, try exact match on `authorities.surname` (full word) first
 *   3. Fall back to LIKE on surname (guarded against very short tokens — see F-001)
 *   4. The first matched Authority is marked as `is_primary = true`
 *   5. Unresolved names are reported in a summary line
 *   6. The match method (exact / last_word / fuzzy) is persisted per-document
 *      under `document.extra.creator_match_log` so operators can audit
 *      low-confidence fuzzy attributions.
 */
class LinkCreatorTextToAuthorities extends Command
{
    protected $signature = 'nra:link-creator-text-to-authorities {--dry-run : Print stats without writing}';

    protected $description = 'Resolve Document.extra.legacy_creator_text into document_authority pivot rows.';

    public function handle(): int
    {
        $authoritiesBySurname = Authority::query()
            ->whereNotNull('surname')
            ->where('surname', '!=', '')
            ->get(['id', 'surname'])
            ->groupBy(fn ($a) => mb_strtolower(trim($a->surname)));

        $linked = 0;
        $methodCounts = ['exact' => 0, 'last_word' => 0, 'fuzzy' => 0];
        $unresolved = [];
        $documents = Document::whereNotNull('extra')->get(['id', 'extra']);

        if (! $this->option('dry-run')) {
            DB::beginTransaction();
        }

        try {
            foreach ($documents as $doc) {
                $text = trim((string) ($doc->extra['legacy_creator_text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $tokens = array_filter(array_map('trim', preg_split('/[;,]+/', $text)));
                $primary = true;
                $matchLog = [];

                foreach ($tokens as $token) {
                    $result = $this->resolveAuthority($token, $authoritiesBySurname);
                    if ($result === null) {
                        $unresolved[$token] = ($unresolved[$token] ?? 0) + 1;
                        continue;
                    }

                    $authorityId = $result['id'];
                    $method = $result['method'];

                    if (! $this->option('dry-run')) {
                        $doc->authorities()->syncWithoutDetaching([
                            $authorityId => ['is_primary' => $primary],
                        ]);
                    }
                    $linked++;
                    if (isset($methodCounts[$method])) {
                        $methodCounts[$method]++;
                    }
                    $matchLog[] = "{$token} → {$method}";
                    $primary = false;
                }

                // Persist the per-document match log so operators can review
                // low-confidence fuzzy attributions later. Only one save per
                // document so the audit-log trait records a single change.
                if (! $this->option('dry-run') && ! empty($matchLog)) {
                    $extra = $doc->extra ?? [];
                    $extra['creator_match_log'] = $matchLog;
                    $doc->extra = $extra;
                    $doc->save();
                }
            }
            if (! $this->option('dry-run')) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if (! $this->option('dry-run')) {
                DB::rollBack();
            }

            throw $e;
        }

        $verb = $this->option('dry-run') ? 'Would link' : 'Linked';

        $this->info('');
        $this->info('═══════════════════════════════════════════════════');
        $this->info(sprintf(
            ' %s Document → Authority pivot rows: %d (exact %d, last_word %d, fuzzy %d)',
            $verb,
            $linked,
            $methodCounts['exact'],
            $methodCounts['last_word'],
            $methodCounts['fuzzy']
        ));
        if ($methodCounts['fuzzy'] > 0) {
            $storageVerb = $this->option('dry-run') ? 'would be stored' : 'stored';
            $this->info(
                "   fuzzy matches {$storageVerb} in document.extra.creator_match_log for review"
            );
        }
        $this->info(' Unresolved creator names: ' . count($unresolved));
        if (! empty($unresolved)) {
            arsort($unresolved);
            $top = array_slice($unresolved, 0, 5, true);
            foreach ($top as $name => $cnt) {
                $this->info("   - \"$name\" ($cnt docs)");
            }
            if (count($unresolved) > 5) {
                $this->info('   ... and ' . (count($unresolved) - 5) . ' more');
            }
        }
        $this->info('═══════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    /**
     * Attempt to resolve a free-text creator token to an Authority row.
     *
     * @return array{id:int, method:string}|null
     *                                           - method = 'exact'     → last-word surname matched an authority surname exactly
     *                                           - method = 'last_word' → first-word fallback matched (handles "Surname Given" order)
     *                                           - method = 'fuzzy'     → LIKE-based fallback (low confidence — guarded by min length)
     *                                           - null                 → no candidate found
     */
    private function resolveAuthority(string $token, $authoritiesBySurname): ?array
    {
        // Last word is usually the surname in "Given Surname" format
        $parts = preg_split('/\s+/', $token);
        $surnameCandidate = mb_strtolower(end($parts));

        // Exact surname match (case-insensitive)
        if (isset($authoritiesBySurname[$surnameCandidate])) {
            $match = $authoritiesBySurname[$surnameCandidate]->first();

            return ['id' => $match->id, 'method' => 'exact'];
        }

        // Try first word as surname (in case "Surname Given" order)
        $firstCandidate = mb_strtolower($parts[0] ?? '');
        if ($firstCandidate !== $surnameCandidate && isset($authoritiesBySurname[$firstCandidate])) {
            return [
                'id' => $authoritiesBySurname[$firstCandidate]->first()->id,
                'method' => 'last_word',
            ];
        }

        // F-001: never fuzzy-match on short tokens — too ambiguous to attribute reliably
        if (mb_strlen($surnameCandidate) < 4) {
            return null;
        }

        // LIKE-based fuzzy: search any authority whose surname contains the token surname
        $hit = Authority::query()
            ->where('surname', 'like', "%{$surnameCandidate}%")
            ->orderByRaw('CHAR_LENGTH(surname) ASC')
            ->limit(1)
            ->value('id');

        if ($hit === null) {
            return null;
        }

        return ['id' => (int) $hit, 'method' => 'fuzzy'];
    }
}
