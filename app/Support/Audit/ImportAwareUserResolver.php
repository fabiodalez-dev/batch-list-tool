<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Filament\Imports\Concerns\LogsImportRows;
use Illuminate\Contracts\Auth\Authenticatable;
use OwenIt\Auditing\Contracts\UserResolver as UserResolverContract;
use OwenIt\Auditing\Resolvers\UserResolver as DefaultUserResolver;

/**
 * Audit user resolver that stays correct during queued imports.
 *
 * Records created by an import run in the queue worker — a process with NO
 * authenticated web/api guard — so owen-it's stock {@see DefaultUserResolver}
 * resolves the actor to null and every imported record's audit (hence the
 * "Inputter" column, which reads the first `created` audit's user) comes out
 * blank, even though a named operator (e.g. Charlene Ellul) launched the
 * import and the source file often carries a "Name of Inputter" column.
 *
 * {@see LogsImportRows::saveRecord()} sets
 * {@see self::$importActor} to the Import's own user for the duration of each
 * row save, so the audit is attributed to the person who started the import.
 * When no import is running, this delegates to owen-it's default behaviour —
 * so nothing changes for ordinary web requests.
 */
class ImportAwareUserResolver implements UserResolverContract
{
    /**
     * The user to attribute audits to while an import row is being saved.
     * Null outside imports → falls back to the default guard-based resolver.
     */
    public static ?Authenticatable $importActor = null;

    /**
     * @return Authenticatable|null
     */
    public static function resolve()
    {
        if (static::$importActor instanceof Authenticatable) {
            return static::$importActor;
        }

        return DefaultUserResolver::resolve();
    }
}
