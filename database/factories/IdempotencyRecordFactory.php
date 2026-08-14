<?php

namespace Database\Factories;

use App\Models\IdempotencyRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyRecord>
 */
class IdempotencyRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory()->withOwnedOrganization(),
            'organization_id' => fn (array $attributes): int => User::findOrFail($attributes['actor_user_id'])->current_organization_id,
            'route' => 'scheduling.entries.store',
            'key_hash' => hash('sha256', fake()->uuid()),
            'request_hash' => hash('sha256', fake()->uuid()),
            'status' => IdempotencyRecord::Processing,
            'response_status' => null,
            'response_body' => null,
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
