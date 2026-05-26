<?php

namespace App\Console\Commands;

use App\Models\Authority;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Match the legacy free-text Creator field on each Document against the
 * Authorities table, populating the document_authority pivot.
 *
 * Strategy (best-effort):
 *   1. Split the legacy_creator_text on ";" — POC allowed multiple creators per document
 *   2. For each token, try exact match on `authorities.surname` first
 *   3. Try first-word match (handles "Surname Given" order)
 *   4. Fall back to LIKE on surname (guarded against very short tokens — F-001)
 *   5. On duplicate-surname collisions: skip + log "ambiguous_N_candidates" so the
 *      operator can resolve manually (F-009 — safest for notarial domain)
 *   6. The first matched Authority is marked as `is_primary = true`
 *   7. Per-document match_method log persisted in `document.extra.creator_match_log`
 *
 * Performance (F-002):
 *   - Documents are processed in `chunkById(500)`; commits per chunk instead of one
 *     long transaction. Robust to interruption and to shared-hosting memory limits.
 *   - Fuzzy LIKE results are memoised in-process so the same misspelled token
 *     does not re-hit MySQL N times.
 */
class LinkCreatorTextToAuthorities extends Command
{
    protected $signature = 'nra:link-creator-text-to-authorities {--dry-run : Print stats without writing}';

    protected $description = 'Resolve Document.extra.legacy_creator_text into document_authority pivot rows.';

    /** @var array<string, ?int> Memo cache for fuzzy resolution */
    private array $fuzzyCache = [];

    public function handle(): int
    {
        $authoritiesBySurname = Authority::query()
            ->whereNotNull('surname')
            ->where('surname', '!=', '')
            ->get(['id', 'surname'])
            ->groupBy(fn ($a) => mb_strtolower(trim($a->surname)));

        $linked = 0;
        $ambiguous = 0;
        $methodCounts = ['exact' => 0, 'last_word' => 0, 'fuzzy' => 0];
        $unresolved = [];
        $totalDocs = Document::whereNotNull('extra')->count();
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Processing {$totalDocs} documents in chunks of 500 …");
        $progressBar = $this->output->createProgressBar($totalDocs);
        $progressBar->start();

        Document::whereNotNull('extra')
            ->orderBy('id')
            ->chunkById(500, function (Collection $documents) use (
                &$linked,
                &$ambiguous,
                &$methodCounts,
                &$unresolved,
                $authoritiesBySurname,
                $isDryRun,
                $progressBar
            ) {
                // F-002: commit per chunk (no long-running transaction).
                if (! $isDryRun) {
                    DB::beginTransaction();
                }

                try {
                    foreach ($documents as $doc) {
                        $text = trim((string) ($doc->extra['legacy_creator_text'] ?? ''));
                        if ($text === '') {
                            $progressBar->advance();
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

                            // F-009: ambiguous → skip + log, do not pick arbitrarily
                            if (isset($result['ambiguous'])) {
                                $matchLog[] = "{$token} → ambiguous_{$result['ambiguous']}_candidates";
                                $ambiguous++;
                                continue;
                            }

                            $authorityId = $result['id'];
                            $method = $result['method'];

                            if (! $isDryRun) {
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

                        // Persist log so operators can review fuzzy + ambiguous attributions
                        if (! $isDryRun && ! empty($matchLog)) {
                            $extra = $doc->extra ?? [];
                            $extra['creator_match_log'] = $matchLog;
                            $doc->extra = $extra;
                            $doc->save();
                        }

                        $progressBar->advance();
                    }

                    if (! $isDryRun) {
                        DB::commit();
                    }
                } catch (\Throwable $e) {
                    if (! $isDryRun) {
                        DB::rollBack();
                    }

                    throw $e;
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $verb = $isDryRun ? 'Would link' : 'Linked';
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
            $sv = $isDryRun ? 'would be stored' : 'stored';
            $this->info("   fuzzy matches {$sv} in document.extra.creator_match_log for review");
        }
        if ($ambiguous > 0) {
            $this->warn(" Ambiguous (skipped — operator must assign manually): {$ambiguous}");
            $this->warn('   filter the Document list by extra.creator_match_log containing "ambiguous" to find them');
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
     * @return array{id:int, method:string}|array{ambiguous:int}|null
     */
    private function resolveAuthority(string $token, $authoritiesBySurname): ?array
    {
        $parts = preg_split('/\s+/', $token);
        $surnameCandidate = mb_strtolower(end($parts));

        // 1. Exact surname match
        if (isset($authoritiesBySurname[$surnameCandidate])) {
            $candidates = $authoritiesBySurname[$surnameCandidate];
            if ($candidates->count() > 1) {
                return ['ambiguous' => $candidates->count()];  // F-009
            }

            return ['id' => $candidates->first()->id, 'method' => 'exact'];
        }

        // 2. First-word fallback ("Surname Given" order)
        $firstCandidate = mb_strtolower($parts[0] ?? '');
        if ($firstCandidate !== $surnameCandidate && isset($authoritiesBySurname[$firstCandidate])) {
            $candidates = $authoritiesBySurname[$firstCandidate];
            if ($candidates->count() > 1) {
                return ['ambiguous' => $candidates->count()];  // F-009
            }

            return ['id' => $candidates->first()->id, 'method' => 'last_word'];
        }

        // 3. F-001 — never fuzzy-match on short tokens
        if (mb_strlen($surnameCandidate) < 4) {
            return null;
        }

        // 4. Fuzzy LIKE (memoised — F-002)
        if (array_key_exists($surnameCandidate, $this->fuzzyCache)) {
            $hit = $this->fuzzyCache[$surnameCandidate];
        } else {
            // CHAR_LENGTH() is MySQL-specific; SQLite (test driver) and
            // PostgreSQL both accept LENGTH(). We use whichever the current
            // connection supports so the command is portable across drivers.
            $lengthFn = DB::connection()->getDriverName() === 'mysql'
                ? 'CHAR_LENGTH'
                : 'LENGTH';
            $hit = Authority::query()
                ->where('surname', 'like', "%{$surnameCandidate}%")
                ->orderByRaw("{$lengthFn}(surname) ASC")
                ->limit(1)
                ->value('id');
            $this->fuzzyCache[$surnameCandidate] = $hit;
        }

        if ($hit === null) {
            return null;
        }

        return ['id' => (int) $hit, 'method' => 'fuzzy'];
    }
}
