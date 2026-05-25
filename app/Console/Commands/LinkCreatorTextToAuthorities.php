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
 *   3. Fall back to LIKE on surname
 *   4. The first matched Authority is marked as `is_primary = true`
 *   5. Unresolved names are reported in a summary line
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

                foreach ($tokens as $token) {
                    $authorityId = $this->resolveAuthority($token, $authoritiesBySurname);
                    if ($authorityId === null) {
                        $unresolved[$token] = ($unresolved[$token] ?? 0) + 1;
                        continue;
                    }
                    if (! $this->option('dry-run')) {
                        $doc->authorities()->syncWithoutDetaching([
                            $authorityId => ['is_primary' => $primary],
                        ]);
                    }
                    $linked++;
                    $primary = false;
                }
            }
            if (! $this->option('dry-run')) {
                DB::commit();
            }
        } catch (\Throwable $e) {
            if (! $this->option('dry-run')) DB::rollBack();
            throw $e;
        }

        $this->info('');
        $this->info('═══════════════════════════════════════════════════');
        $this->info(' Linked Document → Authority pivot rows: ' . $linked);
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

    private function resolveAuthority(string $token, $authoritiesBySurname): ?int
    {
        // Last word is usually the surname in "Given Surname" format
        $parts = preg_split('/\s+/', $token);
        $surnameCandidate = mb_strtolower(end($parts));

        // Exact surname match (case-insensitive)
        if (isset($authoritiesBySurname[$surnameCandidate])) {
            $match = $authoritiesBySurname[$surnameCandidate]->first();
            return $match->id;
        }

        // Try first word as surname (in case "Surname Given" order)
        $firstCandidate = mb_strtolower($parts[0] ?? '');
        if ($firstCandidate !== $surnameCandidate && isset($authoritiesBySurname[$firstCandidate])) {
            return $authoritiesBySurname[$firstCandidate]->first()->id;
        }

        // LIKE-based fuzzy: search any authority whose surname contains the token surname
        $hit = Authority::query()
            ->where('surname', 'like', "%{$surnameCandidate}%")
            ->orderByRaw('CHAR_LENGTH(surname) ASC')
            ->limit(1)
            ->value('id');

        return $hit;
    }
}
