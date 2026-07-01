<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentItem;
use Illuminate\Support\Facades\DB;

/**
 * NAF Queries Q5 — box itemisation service.
 *
 * Expands a single document that stands for many physical items ("71 folders")
 * into an itemised list of {@see DocumentItem} rows — either from a pasted /
 * uploaded list of lines, or as N sequential placeholders. Shared by the
 * Filament UI and unit tests so the "add manually" and "upload from a sheet"
 * paths (which the client said are interchangeable) behave identically.
 */
final class BoxItemisation
{
    /**
     * Create one item per non-empty line. Each line becomes the item's
     * `reference`; if it contains a tab or " | " separator, the part after it
     * becomes the `description`. New items are appended after any existing ones
     * (unless $replace), preserving the pasted order.
     *
     * @param list<string> $lines
     * @return int number of items created
     */
    public static function itemiseFromLines(Document $document, array $lines, bool $replace = false): int
    {
        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t| \| /', $line, 2) ?: [$line];
            $rows[] = [
                'reference' => trim($parts[0]),
                'description' => isset($parts[1]) ? trim($parts[1]) : null,
            ];
        }

        return self::persist($document, $rows, $replace);
    }

    /**
     * Create $count sequential placeholder items ("$prefix 1"… "$prefix N") —
     * the quick path for "this record is N folders" with no per-item detail yet.
     *
     * @return int number of items created
     */
    public static function itemiseCount(Document $document, int $count, string $prefix = 'Item', bool $replace = false): int
    {
        if ($count < 1) {
            return 0;
        }

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['reference' => trim($prefix) . ' ' . $i, 'description' => null];
        }

        return self::persist($document, $rows, $replace);
    }

    /**
     * @param list<array{reference: string, description: ?string}> $rows
     */
    private static function persist(Document $document, array $rows, bool $replace): int
    {
        if ($rows === []) {
            if ($replace) {
                $document->items()->delete();
            }

            return 0;
        }

        return DB::transaction(function () use ($document, $rows, $replace): int {
            if ($replace) {
                $document->items()->delete();
                $start = 1;
            } else {
                $start = (int) $document->items()->max('position') + 1;
            }

            $position = $start;
            foreach ($rows as $row) {
                $document->items()->create([
                    'position' => $position++,
                    'reference' => $row['reference'],
                    'description' => $row['description'],
                ]);
            }

            return count($rows);
        });
    }
}
