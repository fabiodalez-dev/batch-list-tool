<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Accession;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Accession> */
class AccessionFactory extends Factory
{
    protected $model = Accession::class;

    public function definition(): array
    {
        return [
            'code' => 'ACC-' . strtoupper($this->faker->unique()->bothify('######')),
            'accession_number' => $this->faker->unique()->numerify('ACC-####'),
            'accession_date' => $this->faker->date(),
            'notes' => $this->faker->optional()->sentence(),
            'repository_id' => Repository::factory(),
        ];
    }
}
