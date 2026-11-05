<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Filament\Actions\Documents\ActionSupport;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Builders\DocumentBuilder;
use App\Models\Document;
use App\Models\Location;
use App\Support\ActiveRepository;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Models\Audit;

/**
 * What the Boxes bulk actions of client 2026-11-05 share, in the manner of
 * {@see ActionSupport::performBulk()} for documents:
 *
 *   - the selection arrives as ids through the table's scoped query
 *     (`fetchSelectedRecords(false)`): 7,000 selected boxes would not fit in a
 *     web request on the host, and each box is read fresh, again through the
 *     scoped query, just before it is changed;
 *   - each box has its own transaction: a refused box leaves its earlier
 *     neighbours done, and none of its own writes behind;
 *   - the notification says how many were done, which were skipped and why.
 */
final class BoxBulkSupport
{
    /** How many boxes a notification names for one reason. */
    private const int NAMED_PER_REASON = 10;

    /**
     * @param Collection<int, mixed> $keys
     * @param callable(Box): void $perBox throws a DomainException or a ValidationException to refuse the box
     * @return array{done: int, skipped: array<string, list<string>>}
     */
    public static function run(Collection $keys, callable $perBox): array
    {
        $done = 0;
        $skipped = [];

        foreach ($keys as $key) {
            $label = '#' . $key;

            try {
                DB::transaction(static function () use ($key, $perBox, &$label): void {
                    /** @var Box|null $box */
                    $box = Box::query()->withTrashed()->with('batch')->whereKey($key)->lockForUpdate()->first();
                    if ($box === null) {
                        throw new \DomainException('Not available any more.');
                    }
                    $label = $box->shortLabel();
                    if ($box->trashed()) {
                        throw new \DomainException('The box is archived.');
                    }

                    $perBox($box);
                });
                $done++;
            } catch (ValidationException $e) {
                $skipped[collect($e->errors())->flatten()->first() ?? $e->getMessage()][] = $label;
            } catch (\DomainException $e) {
                $skipped[$e->getMessage()][] = $label;
            } catch (\Throwable $e) {
                report($e);
                $skipped['An unexpected error stopped this box; nothing was changed on it.'][] = $label;
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @param array{done: int, skipped: array<string, list<string>>} $result
     */
    public static function notify(array $result, string $doneVerb, string $failedTitle): void
    {
        $done = $result['done'];
        $skippedCount = array_sum(array_map(count(...), $result['skipped']));
        $boxes = $done === 1 ? '1 box' : "{$done} boxes";

        $lines = [];
        foreach ($result['skipped'] as $reason => $labels) {
            $named = array_slice($labels, 0, self::NAMED_PER_REASON);
            $more = count($labels) - count($named);
            $lines[] = e($reason) . ' (' . count($labels) . '): ' . e(implode(', ', $named)) . ($more > 0 ? " and {$more} more" : '');
        }

        $notification = Notification::make()->body($lines === [] ? null : implode('<br>', $lines));

        match (true) {
            $skippedCount === 0 && $done > 0 => $notification->title("{$boxes} {$doneVerb}")->success(),
            $done > 0 => $notification->title("{$boxes} {$doneVerb}, {$skippedCount} skipped")->warning(),
            default => $notification->title($skippedCount > 0 ? "{$failedTitle}: {$skippedCount} skipped" : $failedTitle)->danger(),
        };

        $notification->persistent()->send();
    }

    /** Stop before touching anything. */
    public static function stop(string $title, ?string $body = null): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    /**
     * The target location with this exact name for every repository the selected
     * boxes belong to; null when one of them has none (nothing should be
     * touched then). Keyed by repository id, 0 for a box without a repository.
     *
     * @param Collection<int, mixed> $keys
     * @return array<int, Location>|null
     */
    public static function namedLocations(Collection $keys, string $name): ?array
    {
        $locations = [];
        foreach ($keys->chunk(1000) as $chunk) {
            $rows = Box::query()->whereKey($chunk->all())->toBase()->distinct()
                ->get(['boxes.batch_id', 'boxes.repository_id']);
            $viaBatch = Batch::withoutGlobalScopes()->whereKey($rows->pluck('batch_id')->filter()->unique()->all())->pluck('repository_id', 'id');

            foreach ($rows as $row) {
                $repositoryId = $row->batch_id !== null ? ($viaBatch[$row->batch_id] ?? $row->repository_id) : $row->repository_id;
                $slot = (int) $repositoryId;
                if (array_key_exists($slot, $locations)) {
                    continue;
                }
                $location = Location::namedFor($name, $repositoryId !== null ? (int) $repositoryId : null);
                if ($location === null) {
                    return null;
                }
                $locations[$slot] = $location;
            }
        }

        return $locations;
    }

    /**
     * A location of type Repository or Room (client 2026-11-05: Archive 1,
     * Archive 2… and the rooms), active, from the user's repositories or global.
     */
    public static function archiveLocationSelect(string $name): Select
    {
        return SearchableSelects::location(
            $name,
            fn ($query) => $query
                ->active()
                ->archive()
                ->forRepository(app(ActiveRepository::class)->id() ?? auth()->user()?->default_repository_id),
        );
    }

    /**
     * A posted archive location, checked again on the server: the picker only
     * offers these, a forged post can name any row. Null when it is not usable.
     */
    public static function archiveLocation(mixed $id): ?Location
    {
        if (! filled($id)) {
            return null;
        }
        /** @var Location|null $location */
        $location = Location::withoutGlobalScopes()->find((int) $id);

        return $location !== null && ! $location->trashed() && $location->is_active && $location->isArchive()
            ? $location
            : null;
    }

    /**
     * Global, or of the box's repository. The same refusal for any other
     * repository, so the message says nothing about what that location is.
     */
    public static function assertLocationFitsBox(Location $location, Box $box): void
    {
        if ($location->repository_id !== null && (int) $location->repository_id !== (int) $box->effectiveRepositoryId()) {
            throw new \DomainException('The box belongs to a different repository than the location.');
        }
    }

    /**
     * The documents in a box, archived ones aside: they come back into the box
     * as they were left.
     */
    public static function documentsIn(Box $box): DocumentBuilder
    {
        return Document::withoutGlobalScopes()->whereNull('deleted_at')->where('current_box_id', $box->getKey());
    }

    /**
     * Audit rows for documents written by a bulk update, which the Auditable
     * trait does not see. One insert per 200 rows, not one query per document.
     *
     * @param list<array{id: int, old: array<string, mixed>, new: array<string, mixed>}> $rows
     */
    public static function auditDocuments(array $rows, string $event, string $tags): void
    {
        $now = now();
        $common = [
            'user_type' => auth()->user() ? auth()->user()::class : null,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => Document::class,
            'url' => ActionSupport::safeRequestUrl(),
            'ip_address' => ActionSupport::safeRequestIp(),
            'user_agent' => ActionSupport::safeRequestUserAgent(),
            'tags' => $tags,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (array_chunk($rows, 200) as $chunk) {
            Audit::query()->insert(array_map(static fn (array $row): array => $common + [
                'auditable_id' => $row['id'],
                'old_values' => json_encode($row['old'], JSON_THROW_ON_ERROR),
                'new_values' => json_encode($row['new'], JSON_THROW_ON_ERROR),
            ], $chunk));
        }
    }
}
