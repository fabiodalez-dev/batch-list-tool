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
            'batch_number' => $this->faker->unique()->numberBetween(1, 9999),
            'description' => $this->faker->sentence(),
            'type' => 'MAIN_COLLECTION',
            'is_active' => true,
            'repository_id' => Repository::factory(),
        ];
    }
}
