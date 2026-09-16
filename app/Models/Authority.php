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
    use Auditable;
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    /**
     * ISAAR(CPF) "Level of detail" (client 2026-09-16). Kept here rather than as
     * a database ENUM so the list can grow without a migration, and so a value
     * outside it is rejected where the operator can be told why.
     *
     * @var array<int, string>
     */
    public const LEVELS_OF_DETAIL = ['Minimal', 'Partial', 'Full Level'];

    /**
     * ISAAR(CPF) record status (client 2026-09-16).
     *
     * @var array<int, string>
     */
    public const RECORD_STATUSES = ['In Progress', 'Complete'];

    protected $table = 'authorities';

    protected $fillable = [
        'identifier', 'alternative_identifier', 'alternative_identifier_warrant',
        'surname', 'given_names', 'authorised_form_of_name',
        'entity_type', 'functions_occupations_activities',
        'level_of_detail', 'status', 'rules_and_conventions',
        'date_of_creation', 'creator_of_record',
        'practice_dates_start', 'practice_dates_end', 'notes',
        'ntg_dates_start', 'ntg_dates_end',
    ];

    protected $casts = [
        'practice_dates_start' => 'integer',
        'practice_dates_end' => 'integer',
        'ntg_dates_start' => 'integer',
        'ntg_dates_end' => 'integer',
    ];

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_authority')
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
