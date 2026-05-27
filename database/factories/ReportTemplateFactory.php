<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'repository_id' => Repository::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'source' => $this->faker->randomElement(ReportTemplate::SOURCES),
            'filters' => [],
            'columns' => null,
            'sort' => null,
            'is_shared' => false,
        ];
    }

    /** Mark this template as shared with the rest of the repository. */
    public function shared(): static
    {
        return $this->state(fn (): array => ['is_shared' => true]);
    }

    /** Pin the template to a specific source (one of ReportTemplate::SOURCES). */
    public function source(string $source): static
    {
        return $this->state(fn (): array => ['source' => $source]);
    }
}
