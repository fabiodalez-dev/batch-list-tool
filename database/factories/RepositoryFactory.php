<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Repository>
 */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    public function definition(): array
    {
        // RFQ §3.5.1 — code is the tenant key, must be unique and short.
        return [
            'code'        => strtoupper(Str::random(6)),
            'name'        => $this->faker->company() . ' Archive',
            'description' => $this->faker->sentence(),
            'is_active'   => true,
        ];
    }
}
