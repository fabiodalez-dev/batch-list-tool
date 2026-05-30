<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BackupDestination;

/**
 * Enforces the single-default invariant for backup destinations.
 *
 * Registered in AppServiceProvider::boot() via Model::observe() rather than as
 * a closure inside the model's booted(). A booted() closure binds to the FIRST
 * event dispatcher and goes stale across the per-test application rebuilds the
 * Laravel test harness performs, so the invariant silently stops firing in the
 * full suite (works in isolation/prod). Registering through the provider
 * re-binds the listener to the current dispatcher on every app refresh.
 */
class BackupDestinationObserver
{
    public function saved(BackupDestination $destination): void
    {
        if (! $destination->is_default) {
            return;
        }

        BackupDestination::query()
            ->whereKeyNot($destination->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
