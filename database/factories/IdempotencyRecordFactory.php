<?php

namespace Database\Factories;

use App\Models\IdempotencyRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

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
            'organization_id' => function (array $attributes): int {
                $organizationId = User::query()
                    ->whereKey((int) $attributes['actor_user_id'])
                    ->firstOrFail()
                    ->current_organization_id;

                if ($organizationId === null) {
                    throw new LogicException('Idempotency records require an actor with a current organization.');
                }

                return $organizationId;
            },
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
