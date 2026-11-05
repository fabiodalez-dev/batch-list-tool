<?php

namespace App\Models\Lookup;

use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for documents.custody_status.
 *
 * Client 2026-11-05: "A new page to be able to add/edit/view/delete." `code` is
 * what the document stores, so a code documents hold can be neither changed nor
 * deleted: the document would be left holding an unknown one.
 */
class CustodyStatusType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    /**
     * The code relies on this one (a document's column default, mirror from the
     * box, Move to box): it can be renamed, but not deleted, deactivated or
     * re-coded.
     */
    public const string IN_BOX = 'in_box';

    /**
     * Container key of the per-request read. Scoped rather than a static
     * property: the queue worker forgets scoped instances between jobs and each
     * test has its own container, so neither can see a stale list.
     */
    private const string MEMO = 'lookup.custody_status_types';

    protected $table = 'custody_status_types';

    protected $fillable = ['code', 'label', 'sort_order', 'is_active', 'metadata'];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Forget the per-request read. The model does it on every write of its own;
     * a long-running job also needs it for the writes of another process.
     */
    public static function flushMemo(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * @return array<string,string> code=>label of active values
     */
    public static function options(): array
    {
        return self::rows()->where('is_active', true)->pluck('label', 'code')->all();
    }

    /**
     * Every value, inactive too: how a stored code reads on screen, and what a
     * list rule can look for.
     *
     * @return array<string,string> code=>label
     */
    public static function labels(): array
    {
        return self::rows()->pluck('label', 'code')->all();
    }

    /**
     * Every code the lookup holds, active or not. Whether one may be newly
     * assigned is the separate active check.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::rows()->pluck('code')->values()->all();
    }

    public function isProtected(): bool
    {
        return (string) ($this->exists ? $this->getOriginal('code') : $this->code) === self::IN_BOX;
    }

    /**
     * Any document at all: another repository's and a trashed one (it can be
     * restored) would be left holding the unknown code just the same.
     */
    public function isInUse(): bool
    {
        return DB::table('documents')
            ->where('custody_status', $this->exists ? $this->getOriginal('code') : $this->code)
            ->exists();
    }

    /** The identifier is what documents store, so it stays once one holds it. */
    public function codeIsLocked(): bool
    {
        return $this->isProtected() || $this->isInUse();
    }

    /**
     * Why this status cannot be deleted, or null when it can. The message names
     * no count: the list of statuses is shared, documents belong to a repository.
     */
    public function deleteBlockedBecause(): ?string
    {
        if ($this->isProtected()) {
            return "\"{$this->label}\" is needed by the system and cannot be deleted.";
        }

        if ($this->isInUse()) {
            return "\"{$this->label}\" cannot be deleted: documents still have this custody status. Move them to another status first, or deactivate it.";
        }

        return null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            $original = (string) ($type->exists ? $type->getOriginal('code') : $type->code);

            if ($original === self::IN_BOX && ! $type->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => "\"{$type->label}\" is needed by the system and cannot be deactivated.",
                ]);
            }

            if ($type->exists && $type->isDirty('code')) {
                $unchanged = new self(['code' => $original]);
                if ($unchanged->codeIsLocked()) {
                    throw ValidationException::withMessages([
                        'code' => "The identifier \"{$original}\" cannot be changed: " . ($unchanged->isProtected()
                            ? 'it is needed by the system.'
                            : 'documents still hold it.'),
                    ]);
                }
            }
        });

        static::deleting(function (self $type): void {
            $reason = $type->deleteBlockedBecause();
            if ($reason !== null) {
                throw ValidationException::withMessages(['code' => $reason]);
            }
        });

        static::saved(static fn () => self::flushMemo());
        static::deleted(static fn () => self::flushMemo());
    }

    /**
     * @return Collection<int, self>
     */
    private static function rows(): Collection
    {
        if (! app()->bound(self::MEMO)) {
            app()->scoped(self::MEMO, static fn (): Collection => static::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'code', 'label', 'sort_order', 'is_active']));
        }

        /** @var Collection<int, self> */
        return app(self::MEMO);
    }
}
