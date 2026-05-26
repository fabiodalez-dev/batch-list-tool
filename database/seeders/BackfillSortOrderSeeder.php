<?php

namespace Database\Seeders;

use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-shot back-fill of sort_order after the migration that adds the column
 * runs. Without this, every legacy row has NULL sort_order and the reorder
 * UI would show "Order: —" for them.
 *
 * Strategy: per group (batch for boxes, box for documents, global for series),
 * assign sort_order = row_number ordered by id ASC. Idempotent: skip rows that
 * already have a non-NULL sort_order.
 *
 * Run after the migration:
 *   php artisan db:seed --class=BackfillSortOrderSeeder
 */
class BackfillSortOrderSeeder extends Seeder
{
    public function run(): void
    {
        $this->backfillScoped(Box::class, 'batch_id');
        $this->backfillScoped(Document::class, 'current_box_id');
        $this->backfillGlobal(Series::class);
    }

    private function backfillScoped(string $modelClass, string $scopeColumn): void
    {
        $table = (new $modelClass)->getTable();
        $this->command->info("Backfilling sort_order on {$table} grouped by {$scopeColumn}…");

        DB::table($table)
            ->whereNull('sort_order')
            ->orderBy($scopeColumn)
            ->orderBy('id')
            ->select(['id', $scopeColumn])
            ->chunkById(1000, function ($rows) use ($table, $scopeColumn): void {
                $counters = [];
                foreach ($rows as $row) {
                    $key = $row->{$scopeColumn} ?? 'null';
                    $counters[$key] = ($counters[$key] ?? 0) + 1;
                    DB::table($table)->where('id', $row->id)->update([
                        'sort_order' => $counters[$key],
                    ]);
                }
            });
    }

    private function backfillGlobal(string $modelClass): void
    {
        $table = (new $modelClass)->getTable();
        $this->command->info("Backfilling sort_order on {$table} globally…");

        $i = 0;
        DB::table($table)
            ->whereNull('sort_order')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(1000, function ($rows) use ($table, &$i): void {
                foreach ($rows as $row) {
                    $i++;
                    DB::table($table)->where('id', $row->id)->update(['sort_order' => $i]);
                }
            });
    }
}
