<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Box;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Box> */
class BoxFactory extends Factory
{
    protected $model = Box::class;

    public function definition(): array
    {
        return [
            'box_type' => 'RAS',
            'box_number' => $this->faker->unique()->numerify('B###'),
            'batch_id' => Batch::factory(),
            'barcode' => 'BC' . $this->faker->unique()->numerify('########'),
            'barcode_status' => 'IN',
            'is_legacy' => false,
        ];
    }
}
