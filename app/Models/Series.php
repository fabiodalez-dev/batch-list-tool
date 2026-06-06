<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Series extends Model implements AuditableContract, Sortable
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;
    use SortableTrait;

    /**
     * Series are globally ordered — buildSortQuery() default is fine.
     */
    public array $sortable = [
        'order_column_name' => 'sort_order',
        'sort_when_creating' => true,
    ];

    protected $table = 'series';

    protected $fillable = ['sort_order', 'code', 'title', 'description', 'is_wills_series', 'is_active', 'parent_id', 'repository_id'];

    protected $casts = [
        'is_wills_series' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Feedback1 Wave D1 — Series belongs to an optional Repository.
     * NULL means the series is global (shared across repositories).
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Feedback1 Wave D1 — N:N relation to DocumentType via the
     * document_type_series pivot table.
     */
    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'document_type_series')
            ->withTimestamps();
    }

    /**
     * Feedback1 Wave C1.4 — hierarchical Series (multi-level: Top-Level
     * Series → sub-series → sub-sub-series …). Adjacency list via parent_id.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Ancestor chain, ordered root-first, walked via parent_id.
     *
     * @return array<int, Series>
     */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this->parent;
        $guard = 0;

        // The guard is defence-in-depth against a malformed cycle that
        // somehow slipped past the form/closure validation — never loops
        // beyond the number of series rows.
        while ($node !== null && $guard < 100) {
            array_unshift($chain, $node);
            $node = $node->parent;
            $guard++;
        }

        return $chain;
    }

    /**
     * Qualified, breadcrumb-style title using the code path of every ancestor
     * plus this node, e.g. "R › REG › RWL". Used by the table column so the
     * full hierarchy is visible at a glance.
     */
    public function qualifiedTitle(string $separator = ' › '): string
    {
        $codes = array_map(static fn (self $s): string => (string) $s->code, $this->ancestors());
        $codes[] = (string) $this->code;

        return implode($separator, $codes);
    }

    /**
     * All descendants (children, grandchildren, …) of $this — recursive walk
     * over the children relation. Series is a small reference table (tens of
     * rows) so a recursive walk is cheap and avoids a materialised path.
     *
     * @param array<int, bool> $seen Visited ids — guards against infinite
     *                               recursion if the table ever contains a
     *                               cycle (introduced by raw SQL/import that
     *                               bypassed the app-side cycle rule).
     * @return EloquentCollection<int, Series>
     */
    public function descendants(array &$seen = []): EloquentCollection
    {
        /** @var EloquentCollection<int, Series> $collected */
        $collected = new EloquentCollection;

        $seen[(int) $this->getKey()] = true;

        /** @var Series $child */
        foreach ($this->children as $child) {
            $childId = (int) $child->getKey();
            if (isset($seen[$childId])) {
                continue; // cycle — already visited this node
            }
            $seen[$childId] = true;
            $collected->push($child);
            foreach ($child->descendants($seen) as $deep) {
                $collected->push($deep);
            }
        }

        return $collected;
    }

    /**
     * Ids this series may NOT pick as its parent (itself + all descendants),
     * preventing a cycle. Used by the SeriesResource form Select options and
     * the server-side validation rule.
     *
     * @return array<int, int>
     */
    public function disallowedParentIds(): array
    {
        if (! $this->exists) {
            return [];
        }

        return array_merge(
            [(int) $this->getKey()],
            $this->descendants()->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
    }
}
