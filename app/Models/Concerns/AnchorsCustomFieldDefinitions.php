<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * For records that do not carry a repository of their own.
 *
 * Most entities reach a repository directly (Document, Batch, Series, Location,
 * Accession) or through a parent (Box via its batch, Volume via its document),
 * so {@see HasCustomFields::customFieldDefinitions()} can scope definitions by
 * that value and be done.
 *
 * Authorities and Document Types cannot. Authorities are shared across the
 * archive and document_types has no repository_id column at all, so the only
 * repository available is whichever one is ACTIVE in the topbar. That is the
 * right answer for which columns to OFFER — it is already how the template and
 * the importer choose them — but scoping the READ the same way loses data: the
 * active repository is null whenever the operator has "All repositories"
 * selected, and null again inside a queue worker once the import job has
 * finished, so a value written a minute earlier reads back as absent.
 *
 * So the read is anchored to the values themselves as well: a definition this
 * record already holds a value for stays visible whatever the context.
 */
trait AnchorsCustomFieldDefinitions
{
    /**
     * Deliberately NOT named customFieldDefinitions(): that name belongs to
     * {@see HasCustomFields}, and two traits in one class cannot both define
     * it — PHP treats that as a fatal collision. The using model overrides
     * customFieldDefinitions() and returns this, which keeps the override
     * visible in the model rather than hidden behind an `insteadof`.
     *
     * @return Builder<CustomFieldDefinition>
     */
    public function anchoredCustomFieldDefinitions(): Builder
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
}
