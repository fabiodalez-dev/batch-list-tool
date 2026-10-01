<?php

declare(strict_types=1);

namespace App\Support\History;

use App\Models\Accession;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\ColumnLabels\ColumnLabels;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;

/**
 * Everything that ever happened to one box or one document, in one list.
 *
 * The history of a record was spread over five or six tabs — barcodes, seals,
 * locations, box moves, identifiers, flags — plus the audit trail, which held
 * every field change but only as raw ids ("current_box_id 7039 → 7140"). To
 * answer "what happened to this box" one had to open each and line them up by
 * hand. This merges them into one chronology (RFQ §3.1.5–§3.1.8).
 *
 * Each entry: when, what kind of event, which field or subject, from, to, who,
 * and where it came from. An audited update becomes one entry per changed
 * field, with ids resolved to what a person recognises (a box as
 * "RAS 223 · AA34092", a batch as its number, a location as its breadcrumb).
 *
 * Fields that have a dedicated log are taken from that log, not from the
 * audit, so a change is listed once: the dedicated log carries more (a seal's
 * notes, a location's breadcrumb at the time, a barcode's status).
 */
final class Timeline
{
    public const string KIND_CHANGE = 'Field change';

    public const string KIND_CREATED = 'Created';

    public const string KIND_DELETED = 'Deleted';

    public const string KIND_RESTORED = 'Restored';

    public const string KIND_LOCATION = 'Location';

    public const string KIND_BARCODE = 'Barcode';

    public const string KIND_SEAL = 'Seal number';

    public const string KIND_MOVE = 'Box move';

    public const string KIND_IDENTIFIER = 'Identifier';

    public const string KIND_FLAG = 'Flag';

    /** Audit fields never worth a line. */
    private const array NOISE = ['created_at', 'updated_at', 'deleted_at', 'sort_order', 'id'];

    /** @var array<string, string|null> */
    private array $labelMemo = [];

    /** @var array<int, string|null> */
    private array $userMemo = [];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forBox(Box $box): Collection
    {
        return (new self)->boxEntries($box);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forDocument(Document $document): Collection
    {
        return (new self)->documentEntries($document);
    }

    /**
     * The field changes of one audit row, each with a readable label and its
     * old and new values resolved to names — the same wording the History tab
     * uses. Empty for created / deleted / restored events.
     *
     * @return list<array{field: string, label: string, from: ?string, to: ?string}>
     */
    public static function fieldChanges(Audit $audit, ?self $resolver = null): array
    {
        if ((string) $audit->getAttribute('event') !== 'updated') {
            return [];
        }

        $self = $resolver ?? new self;
        $entity = self::entityFor((string) $audit->getAttribute('auditable_type'));
        $old = (array) ($audit->getAttribute('old_values') ?? []);
        $new = (array) ($audit->getAttribute('new_values') ?? []);

        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $field = (string) $field;
            if (in_array($field, self::NOISE, true)) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'label' => $self->fieldLabel($entity, $field),
                'from' => $self->display($field, $old[$field] ?? null),
                'to' => $self->display($field, $new[$field] ?? null),
            ];
        }

        return $changes;
    }

    /**
     * What a person calls the audited record: a box by type, number and
     * barcode, a document by its identifier, anything else by its first
     * recognisable name field — instead of a bare id.
     */
    public static function recordLabel(string $auditableType, mixed $id, ?self $resolver = null): string
    {
        $self = $resolver ?? new self;
        $short = class_basename($auditableType);

        return $self->memo('record:' . $auditableType . ':' . $id, function () use ($self, $auditableType, $short, $id): string {
            if ($auditableType === Box::class) {
                return (string) $self->boxLabel($id);
            }
            if (! class_exists($auditableType) || ! is_subclass_of($auditableType, Model::class)) {
                return $short . ' #' . $id;
            }
            // withoutGlobalScopes() lifts the soft-delete scope too, so a
            // record deleted since is still named.
            $record = $auditableType::query()->withoutGlobalScopes()->find($id);
            if ($record === null) {
                return $short . ' #' . $id . ' (deleted)';
            }
            foreach (['identifier', 'code', 'batch_number', 'name', 'title', 'email', 'surname'] as $attribute) {
                $value = $record->getAttribute($attribute);
                if ($value !== null && $value !== '') {
                    return $short . ' ' . $value;
                }
            }

            return $short . ' #' . $id;
        }) ?? ($short . ' #' . $id);
    }

    /**
     * The renameable-columns key of a model class: Box → box, DocumentType →
     * documentType.
     */
    public static function entityFor(string $auditableType): string
    {
        return Str::camel(class_basename($auditableType));
    }

    /**
     * Newest first; undated legacy moves at the end, in their recorded order.
     *
     * @param Collection<int, array<string, mixed>> $entries
     * @return Collection<int, array<string, mixed>>
     */
    public static function sortNewestFirst(Collection $entries): Collection
    {
        return $entries->sort(function (array $a, array $b): int {
            $aDated = $a['at'] instanceof CarbonInterface;
            $bDated = $b['at'] instanceof CarbonInterface;
            if ($aDated !== $bDated) {
                return $aDated ? -1 : 1;
            }
            if ($aDated && $bDated && ! $a['at']->equalTo($b['at'])) {
                return $b['at'] <=> $a['at'];
            }

            return [$b['sequence'], $b['key']] <=> [$a['sequence'], $a['key']];
        })->values();
    }

    /**
     * A resolver to pass to fieldChanges() / recordLabel() when labelling many
     * audit rows at once (a page, an export), so they share their lookups.
     * Deliberately not a static singleton: in a long-lived worker or a test
     * run, a process-wide memo would keep yesterday's names for reused ids.
     */
    public static function resolver(): self
    {
        return new self;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function boxEntries(Box $box): Collection
    {
        $entries = $this->auditEntries($box, 'box', ['location_id', 'seal_number', 'barcode', 'barcode_status']);

        foreach ($box->barcodeHistory()->get() as $row) {
            $entries->push($this->entry(
                'barcode-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_BARCODE,
                'Barcode',
                $this->barcodeWithStatus($row->getAttribute('previous_barcode'), $row->getAttribute('previous_status')),
                $this->barcodeWithStatus($row->getAttribute('new_barcode'), $row->getAttribute('new_status')),
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('source') ?? $row->getAttribute('reason')
            ));
        }

        foreach ($box->sealNumberHistory()->get() as $row) {
            $entries->push($this->entry(
                'seal-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_SEAL,
                'Seal number',
                $row->getAttribute('old_value'),
                $row->getAttribute('new_value'),
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('notes')
            ));
        }

        foreach ($box->locationHistory()->get() as $row) {
            $entries->push($this->entry(
                'location-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_LOCATION,
                'Location',
                $row->getAttribute('from_location_label'),
                $row->getAttribute('to_location_label'),
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('notes')
            ));
        }

        $moves = BoxMovement::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('from_box_id', $box->getAttribute('id'))->orWhere('to_box_id', $box->getAttribute('id')))
            ->with('document:id,identifier')
            ->get();
        foreach ($moves as $move) {
            $incoming = (int) $move->getAttribute('to_box_id') === (int) $box->getAttribute('id');
            $document = $move->getRelationValue('document')?->getAttribute('identifier') ?? ('document #' . $move->getAttribute('document_id'));
            $entries->push($this->entry(
                'move-' . $move->getAttribute('id'),
                $move->getAttribute('movement_date'),
                self::KIND_MOVE,
                $incoming ? "Document {$document} came in" : "Document {$document} left",
                $this->boxLabel($move->getAttribute('from_box_id')),
                $this->boxLabel($move->getAttribute('to_box_id')),
                $move->getAttribute('user_id'),
                $this->moveSource($move),
                (int) $move->getAttribute('sequence')
            ));
        }

        return self::sortNewestFirst($entries);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentEntries(Document $document): Collection
    {
        $entries = $this->auditEntries($document, 'document', ['location_id', 'identifier']);

        foreach ($document->identifierHistory()->get() as $row) {
            $entries->push($this->entry(
                'identifier-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_IDENTIFIER,
                'Identifier',
                trim(($row->getAttribute('previous_identifier') ?? '') . ($row->getAttribute('previous_volume') ? ' · vol. ' . $row->getAttribute('previous_volume') : '')) ?: null,
                trim(($row->getAttribute('new_identifier') ?? '') . ($row->getAttribute('new_volume') ? ' · vol. ' . $row->getAttribute('new_volume') : '')) ?: null,
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('reason')
            ));
        }

        foreach ($document->barcodeHistory()->get() as $row) {
            $entries->push($this->entry(
                'dbarcode-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_BARCODE,
                'Barcode',
                $row->getAttribute('old_value'),
                $row->getAttribute('new_value'),
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('notes')
            ));
        }

        foreach ($document->locationHistory()->get() as $row) {
            $entries->push($this->entry(
                'dlocation-' . $row->getAttribute('id'),
                $row->getAttribute('changed_at'),
                self::KIND_LOCATION,
                'Location',
                $row->getAttribute('from_location_label'),
                $row->getAttribute('to_location_label'),
                $row->getAttribute('changed_by_user_id'),
                $row->getAttribute('notes')
            ));
        }

        foreach ($document->movements()->get() as $move) {
            $entries->push($this->entry(
                'move-' . $move->getAttribute('id'),
                $move->getAttribute('movement_date'),
                self::KIND_MOVE,
                'Moved between boxes',
                $this->boxLabel($move->getAttribute('from_box_id')),
                $this->boxLabel($move->getAttribute('to_box_id')),
                $move->getAttribute('user_id'),
                $this->moveSource($move),
                (int) $move->getAttribute('sequence')
            ));
        }

        foreach ($document->flags()->get() as $flag) {
            $entries->push($this->entry(
                'flag-' . $flag->getAttribute('id'),
                $flag->getAttribute('flagged_at'),
                self::KIND_FLAG,
                'Flag raised: ' . $flag->getAttribute('title'),
                null,
                Str::headline((string) $flag->getAttribute('type')) . ' · ' . $flag->getAttribute('severity'),
                $flag->getAttribute('flagged_by_user_id'),
                null
            ));
            if ($flag->getAttribute('resolved_at') !== null) {
                $entries->push($this->entry(
                    'flag-resolved-' . $flag->getAttribute('id'),
                    $flag->getAttribute('resolved_at'),
                    self::KIND_FLAG,
                    'Flag ' . $flag->getAttribute('status') . ': ' . $flag->getAttribute('title'),
                    'open',
                    (string) $flag->getAttribute('status'),
                    $flag->getAttribute('resolved_by_user_id'),
                    $flag->getAttribute('resolution_notes')
                ));
            }
        }

        return self::sortNewestFirst($entries);
    }

    /**
     * @param list<string> $coveredElsewhere fields read from a dedicated log instead
     * @return Collection<int, array<string, mixed>>
     */
    private function auditEntries(Model $record, string $entity, array $coveredElsewhere): Collection
    {
        $entries = collect();

        $audits = Audit::query()
            ->where('auditable_type', $record->getMorphClass())
            ->where('auditable_id', $record->getKey())
            ->orderBy('id')
            ->get();

        foreach ($audits as $audit) {
            $event = (string) $audit->getAttribute('event');
            $when = $audit->getAttribute('created_at');
            $by = $audit->getAttribute('user_id') !== null ? (int) $audit->getAttribute('user_id') : null;
            $source = $this->auditSource($audit);

            if (in_array($event, ['created', 'deleted', 'restored'], true)) {
                $kind = match ($event) {
                    'created' => self::KIND_CREATED,
                    'deleted' => self::KIND_DELETED,
                    default => self::KIND_RESTORED,
                };
                $entries->push($this->entry('audit-' . $audit->getAttribute('id'), $when, $kind, Str::headline($entity) . ' ' . $event, null, null, $by, $source));

                continue;
            }

            $old = (array) ($audit->getAttribute('old_values') ?? []);
            $new = (array) ($audit->getAttribute('new_values') ?? []);
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
                if (in_array($field, self::NOISE, true) || in_array($field, $coveredElsewhere, true)) {
                    continue;
                }
                $entries->push($this->entry(
                    'audit-' . $audit->getAttribute('id') . '-' . $field,
                    $when,
                    self::KIND_CHANGE,
                    $this->fieldLabel($entity, (string) $field),
                    $this->display((string) $field, $old[$field] ?? null),
                    $this->display((string) $field, $new[$field] ?? null),
                    $by,
                    $source,
                ));
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $key, mixed $at, string $kind, string $subject, ?string $from, ?string $to, ?int $userId, ?string $source, int $sequence = 0): array
    {
        return [
            'key' => $key,
            'at' => $at instanceof CarbonInterface ? $at : ($at ? Carbon::parse((string) $at) : null),
            'kind' => $kind,
            'subject' => $subject,
            'from' => $from === '' ? null : $from,
            'to' => $to === '' ? null : $to,
            'by' => $this->userName($userId),
            'source' => $source === '' ? null : $source,
            'sequence' => $sequence,
        ];
    }

    private function fieldLabel(string $entity, string $field): string
    {
        $fixed = [
            'batch_id' => 'Batch', 'current_box_id' => 'Current box', 'parent_box_id' => 'Parent box',
            'series_id' => 'Subseries', 'location_id' => 'Location', 'accession_id' => 'Accession',
            'repository_id' => 'Repository', 'destroyed_at' => 'Destroyed', 'extra' => 'Additional data',
        ];

        if (isset($fixed[$field])) {
            return $fixed[$field];
        }

        $defaults = ColumnLabels::DEFAULTS[$entity] ?? [];

        return array_key_exists($field, $defaults) ? ColumnLabels::get($entity, $field) : Str::headline($field);
    }

    private function display(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'batch_id' => $this->memo('batch:' . $value, fn () => Batch::withoutGlobalScopes()->whereKey($value)->value('batch_number')),
            'current_box_id', 'parent_box_id' => $this->boxLabel((int) $value),
            'series_id' => $this->memo('series:' . $value, fn () => Series::withoutGlobalScopes()->whereKey($value)->value('code')),
            'location_id' => $this->memo('location:' . $value, fn () => Location::withoutGlobalScopes()->find($value)?->breadcrumb()),
            'accession_id' => $this->memo('accession:' . $value, fn () => Accession::withoutGlobalScopes()->whereKey($value)->value('code')),
            'repository_id' => $this->memo('repository:' . $value, fn () => Repository::query()->whereKey($value)->value('code')),
            default => match (true) {
                is_bool($value) => $value ? 'Yes' : 'No',
                is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                default => (string) $value,
            },
        } ?? ('#' . $value);
    }

    private function boxLabel(mixed $boxId): ?string
    {
        if ($boxId === null || $boxId === '') {
            return null;
        }

        return $this->memo('box:' . $boxId, function () use ($boxId): string {
            $box = Box::withoutGlobalScopes()->withTrashed()->find($boxId, ['id', 'box_type', 'box_number', 'barcode']);
            if ($box === null) {
                return 'box #' . $boxId . ' (deleted)';
            }

            return trim($box->getAttribute('box_type') . ' ' . $box->getAttribute('box_number') . ($box->getAttribute('barcode') ? ' · ' . $box->getAttribute('barcode') : ''));
        });
    }

    private function barcodeWithStatus(?string $barcode, ?string $status): ?string
    {
        if ($barcode === null || $barcode === '') {
            return $status;
        }

        return $status ? "{$barcode} ({$status})" : $barcode;
    }

    private function moveSource(Model $move): string
    {
        $how = $move->getAttribute('date_source') === BoxMovement::DATE_SOURCE_LEGACY ? 'From the legacy sheet (undated)' : 'Recorded';

        return trim($how . ($move->getAttribute('reason') ? ' — ' . $move->getAttribute('reason') : ''));
    }

    private function auditSource(Audit $audit): ?string
    {
        $url = (string) $audit->getAttribute('url');

        return match (true) {
            $url === '' => null,
            str_contains($url, 'console') => 'Import or system task',
            str_contains($url, 'livewire') => 'Edited in the app',
            default => 'Web',
        };
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return $this->userMemo[$userId] ??= User::query()->whereKey($userId)->value('name') ?? ('user #' . $userId);
    }

    /**
     * @param callable(): mixed $resolve
     */
    private function memo(string $key, callable $resolve): ?string
    {
        if (! array_key_exists($key, $this->labelMemo)) {
            $value = $resolve();
            $this->labelMemo[$key] = $value === null ? null : (string) $value;
        }

        return $this->labelMemo[$key];
    }
}
