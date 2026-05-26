<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Repository> */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'is_active' => true,
        ];
    }
}
