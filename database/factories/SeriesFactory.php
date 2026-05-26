<?php

namespace Database\Factories;

use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    protected $model = Series::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(4)),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_wills_series' => false,
            'is_active' => true,
        ];
    }
}
