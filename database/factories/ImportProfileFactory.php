<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImportProfile;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportProfile>
 */
class ImportProfileFactory extends Factory
{
    protected $model = ImportProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'repository_id' => Repository::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'import_type' => $this->faker->randomElement(ImportProfile::TYPES),
            'column_map' => [
                'identifier' => 'Identifier',
                'surname' => 'Creator Surname',
            ],
            'synonyms' => null,
            'is_shared' => false,
            'last_used_at' => null,
            'use_count' => 0,
        ];
    }

    /** Mark this profile as shared with the rest of the repository. */
    public function shared(): static
    {
        return $this->state(fn (): array => ['is_shared' => true]);
    }

    /** Pin the profile to a specific import_type (one of ImportProfile::TYPES). */
    public function ofType(string $type): static
    {
        return $this->state(fn (): array => ['import_type' => $type]);
    }
}
