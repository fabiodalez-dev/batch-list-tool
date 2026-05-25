<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Authority extends Model implements AuditableContract
{
    use HasFactory;
    use SoftDeletes;
    use Auditable;
    use Searchable;

    protected $table = 'authorities';

    protected $fillable = [
        'identifier', 'alternative_identifier', 'surname', 'given_names',
        'entity_type', 'practice_dates_start', 'practice_dates_end', 'notes',
    ];

    protected $casts = [
        'practice_dates_start' => 'integer',
        'practice_dates_end' => 'integer',
    ];

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function accessions(): HasMany
    {
        return $this->hasMany(Accession::class);
    }

    public function toSearchableArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'alternative_identifier' => $this->alternative_identifier,
            'surname' => $this->surname,
            'given_names' => $this->given_names,
        ];
    }
}
