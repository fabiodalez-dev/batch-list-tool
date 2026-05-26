<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentSealNumberHistory;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSealNumberHistory>
 */
class DocumentSealNumberHistoryFactory extends Factory
{
    protected $model = DocumentSealNumberHistory::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'previous_seal_number' => 'S-' . fake()->numberBetween(1, 9999) . '-old',
            'new_seal_number' => 'S-' . fake()->numberBetween(1, 9999),
            'changed_at' => fake()->dateTimeBetween('-2 years', 'now'),
            'changed_by_user_id' => User::factory(),
            'reason' => fake()->optional()->sentence(6),
            'repository_id' => Repository::factory(),
        ];
    }

    /**
     * No operator note attached.
     */
    public function withoutReason(): static
    {
        return $this->state(fn () => ['reason' => null]);
    }

    /**
     * Back-fill: an explicit historical timestamp.
     */
    public function backDatedTo(string|\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['changed_at' => $when]);
    }

    /**
     * Anonymous transition (no operator captured) — e.g. legacy import.
     */
    public function anonymous(): static
    {
        return $this->state(fn () => ['changed_by_user_id' => null]);
    }
}
