<?php

namespace App\Models\Lookup;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controlled vocabulary for the document's current-box physical type
 * (RFQ §3.1.11 / App.2-ix). Seeded from
 * {@see Document::CURRENT_BOX_TYPES}; `counts_as` is the
 * disinfestation weight (Big Brown Box counts as 2 against the 250/cycle limit).
 */
class CurrentBoxType extends Model
{
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
}
