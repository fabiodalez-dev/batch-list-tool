<?php

namespace App\Models;

use App\Models\Lookup\Concerns\HasLookupOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Controlled vocabulary for Location types (Feedback1 gaps — editable
 * Room / Museum / Repository lookup, mirroring {@see Lookup\BoxType}).
 *
 * Seeded from {@see Location::CANONICAL_TYPES} inside the
 * create_location_types migration; the `code` (lowercase, e.g. 'room') is
 * what {@see Location::$type} stores, so existing rows stay compatible.
 */
class LocationType extends Model implements AuditableContract
{
    use Auditable;
    use HasLookupOptions;

    protected $table = 'location_types';

    protected $fillable = ['code', 'repository_id', 'label', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

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
