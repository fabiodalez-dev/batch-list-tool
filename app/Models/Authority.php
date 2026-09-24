<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Database\Eloquent\Builder;
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
    use HasCustomFields;
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
        'previous_temporary_identifiers',
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

    /**
     * Authorities are not owned by a repository — there is no repository_id on
     * the table, and a notary can appear in documents across several archives.
     * The inherited implementation therefore reads a null and scopes the custom
     * field definitions to repository NULL, which matches nothing: the importer
     * accepted an added column and then dropped its value without a word.
     *
     * The active repository is the right scope because it is already the one
     * the template and the importer use to decide WHICH added columns exist.
     * Reading them back under a different scope than they were written is what
     * created the gap.
     *
     * @see HasCustomFields::customFieldRepositoryId()
     */
    public function customFieldRepositoryId(): ?int
    {
        return CustomFieldResolver::activeRepositoryId();
    }

    /**
     * Which added columns this authority answers to.
     *
     * The active repository decides which columns are OFFERED — that is already
     * how the template and the importer choose them, so offering a different set
     * here would be the inconsistency. But scoping the READ the same way loses
     * data: the active repository is null whenever the operator has "All
     * repositories" selected, and null again inside a queue worker once the
     * import job has finished, so a value written a minute earlier reads back
     * as absent.
     *
     * So the read is anchored to the values themselves as well: a definition
     * this record already holds a value for stays visible whatever the context.
     * Authorities are the only entity that needs this — Box and Volume reach a
     * repository through their batch and document, and cannot drift.
     *
     * @return Builder<CustomFieldDefinition>
     */
    public function customFieldDefinitions(): Builder
    {
        $activeRepository = $this->customFieldRepositoryId();
        $anchored = $this->exists
            ? $this->customFieldValues()->pluck('custom_field_definition_id')->all()
            : [];

        return CustomFieldDefinition::query()
            ->where('entity_type', $this->customFieldEntityType())
            ->where('is_active', true)
            ->where(function (Builder $query) use ($activeRepository, $anchored): void {
                if ($activeRepository === null && $anchored === []) {
                    // Neither a repository nor a stored value to go on. Matching
                    // nothing is the only safe answer: matching every repository's
                    // definitions would show one archive's columns on another's.
                    $query->whereRaw('1 = 0');

                    return;
                }
                if ($activeRepository !== null) {
                    $query->where('repository_id', $activeRepository);
                }
                if ($anchored !== []) {
                    $query->orWhereIn('id', $anchored);
                }
            })
            ->orderBy('sort_order');
    }

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
