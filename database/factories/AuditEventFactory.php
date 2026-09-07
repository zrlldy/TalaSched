<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'actor_user_id' => User::factory(),
            'impersonator_user_id' => null,
            'correlation_id' => (string) Str::uuid(),
            'action' => 'audit.test_recorded',
            'subject_type' => Organization::class,
            'subject_id' => fn (array $attributes): string => Organization::query()
                ->findOrFail((int) $attributes['organization_id'])
                ->public_id,
            'before' => ['status' => 'pending'],
            'after' => ['status' => 'active'],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'occurred_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $organization->getKey(),
            'subject_id' => $organization->public_id,
        ]);
    }
}
