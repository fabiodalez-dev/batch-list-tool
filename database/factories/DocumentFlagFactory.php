<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentFlag>
 */
class DocumentFlagFactory extends Factory
{
    protected $model = DocumentFlag::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'type' => fake()->randomElement(DocumentFlag::TYPES),
            'severity' => fake()->randomElement(DocumentFlag::SEVERITIES),
            'status' => 'open',
            'title' => fake()->sentence(5),
            'description' => fake()->optional()->paragraph(),
            'context' => null,
            'flagged_by_user_id' => User::factory(),
            'flagged_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_notes' => null,
            // repository_id is mirrored from the parent document by the
            // model's `setDocumentIdAttribute` mutator — no need to set it
            // here, and we deliberately leave it out so the mirror runs.
        ];
    }

    /** Already-resolved flag (closed). */
    public function resolved(?string $notes = null): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_by_user_id' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => $notes ?? 'Closed by test fixture.',
        ]);
    }

    /** Dismissed (false positive). */
    public function dismissed(?string $notes = null): static
    {
        return $this->state(fn () => [
            'status' => 'dismissed',
            'resolved_by_user_id' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => $notes ?? 'Dismissed by test fixture.',
        ]);
    }

    /** Acknowledged but still open. */
    public function acknowledged(): static
    {
        return $this->state(fn () => ['status' => 'acknowledged']);
    }

    /** Critical-severity flag — used to exercise the "danger" branches. */
    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    /** Convenience: type = duplicate_suspect, with a structured context payload. */
    public function duplicateSuspect(?int $duplicateOf = null): static
    {
        return $this->state(fn () => [
            'type' => 'duplicate_suspect',
            'context' => $duplicateOf !== null ? ['duplicate_of' => $duplicateOf] : null,
        ]);
    }
}
