<?php

declare(strict_types=1);

namespace App\Filament\Imports\Concerns;

use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Illuminate\Database\Eloquent\Model;

/**
 * RFQ §3.1.3 — honours the Import Wizard's "Skip rows that already exist"
 * checkbox (`skip_duplicates`, surfaced as `$this->options['skip_duplicates']`).
 *
 * Each importer's {@see Importer::resolveRecord()}
 * locates an existing record (idempotent upsert key) or returns a fresh
 * model. Calling {@see skipIfDuplicate()} on that result lets the operator
 * opt into SKIP-instead-of-update semantics: when `skip_duplicates` is true
 * and the matched record already exists, a {@see RowImportFailedException} is
 * thrown. The {@see ImportCsv} job catches it
 * and records the row in the downloadable "failed rows" CSV. When the option
 * is absent/false the historical upsert behaviour is preserved.
 *
 * @property array<string, mixed> $options
 */
trait SkipsExistingRows
{
    /**
     * Throw a row-skip exception when `skip_duplicates` is enabled AND the
     * resolved record already exists in the database. Otherwise returns
     * cleanly so the caller can `return` its own concrete model — keeping the
     * importer's `resolveRecord(): ?ConcreteModel` return type intact.
     *
     * @throws RowImportFailedException
     */
    protected function skipIfDuplicate(?Model $record): void
    {
        throw_if($record instanceof Model
        && $record->exists
        && ($this->options['skip_duplicates'] ?? false), RowImportFailedException::class, 'Skipped — row already exists (skip duplicates enabled).');
    }
}
