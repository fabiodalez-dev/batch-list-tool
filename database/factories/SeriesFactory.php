<?php

namespace Database\Factories;

use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Series> */
class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'is_wills_series' => false,
            'is_active' => true,
        ];
    }
}
