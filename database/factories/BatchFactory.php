<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Batch> */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        return [
            // Exclude reserved numbers: 34/36 are forbidden (DB CHECK) and 50
            // is wills-only — a random hit on 50 makes any test that attaches
            // a non-wills document trip the wills-batch invariant (flaky CI,
            // observed on PR #139's pull_request run).
            'batch_number' => $this->faker->unique()->randomElement(
                array_values(array_diff(range(1, 9999), [...Batch::FORBIDDEN_NUMBERS, Batch::WILLS_BATCH]))
            ),
            'description' => $this->faker->sentence(),
            'type' => 'MAIN_COLLECTION',
            'is_active' => true,
            'repository_id' => Repository::factory(),
        ];
    }
}
