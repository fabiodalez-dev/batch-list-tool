<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Settings\AuditSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;

/**
 * Applies the retention threshold saved on the Audit settings page
 * (AuditSettings::threshold, in days; 0 = keep forever): deletes the audit
 * rows created before the cut-off, in chunks so a large first run does not
 * hold one long lock on the audits table. Scheduled daily in routes/console.php.
 */
class PruneAudits extends Command
{
    protected $signature = 'nra:prune-audits
        {--dry-run : Count the rows that would be deleted without deleting them}
        {--chunk=1000 : Rows deleted per statement}';

    protected $description = 'Delete audit log rows older than the retention threshold set on the Audit settings page.';

    public function handle(): int
    {
        try {
            $days = (int) app(AuditSettings::class)->threshold;
        } catch (\Throwable $throwable) {
            $this->error('Audit settings could not be read: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        if ($days <= 0) {
            $this->info('Audit retention is 0 (keep forever): nothing deleted.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));

        if ($this->option('dry-run')) {
            $count = $this->olderThan($cutoff)->count();
            $this->info("Dry run: {$count} audit rows older than {$days} days (before {$cutoff->toDateTimeString()}) would be deleted.");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = $this->olderThan($cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += $this->newQuery()->whereKey($ids)->delete();
        } while (count($ids) === $chunk);

        Log::info('nra:prune-audits removed audit rows older than the retention threshold.', [
            'deleted' => $deleted,
            'threshold_days' => $days,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        $this->info("Deleted {$deleted} audit rows older than {$days} days (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Model>
     */
    private function olderThan(Carbon $cutoff): Builder
    {
        return $this->newQuery()->where('created_at', '<', $cutoff);
    }

    /**
     * @return Builder<Model>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<Model> $model */
        $model = config('audit.implementation', Audit::class);

        return $model::query();
    }
}
