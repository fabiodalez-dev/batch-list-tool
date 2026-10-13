<?php

namespace App\Models\Lookup;

use App\Models\Document;
use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for the document's current-box physical type
 * (RFQ §3.1.11 / App.2-ix). Seeded from
 * {@see Document::CURRENT_BOX_TYPES}; `counts_as` is the
 * disinfestation weight (Big Brown Box counts as 2 against the 250/cycle limit).
 */
class CurrentBoxType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    protected $table = 'current_box_types';

    protected $fillable = ['code', 'label', 'sort_order', 'is_active', 'metadata', 'counts_as'];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'counts_as' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * @return array<string,string> code=>label of active values
     */
    public static function options(): array
    {
        return static::active()->pluck('label', 'code')->all();
    }

    /**
     * Every code the lookup holds (active or not), plus the three seeded ones
     * as a floor before it is seeded. A document's value must be one of these;
     * whether it may be newly assigned is the separate active check.
     *
     * Client 2026-10-13: "Conservation Box" was added on the Physical Box Type
     * page but a document could not carry it, because the document checked a
     * fixed list of three types instead of this table.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_values(array_unique([
            ...Document::CURRENT_BOX_TYPES,
            ...static::query()->orderBy('sort_order')->pluck('code')->all(),
        ]));
    }
}
