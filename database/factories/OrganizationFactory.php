<?php

namespace Database\Factories;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'owner_user_id' => User::factory(),
        ];
    }

    /**
     * Configure the factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            $organization->memberships()->updateOrCreate(
                ['user_id' => $organization->owner_user_id],
                ['role' => OrganizationRole::Owner],
            );

            app(ProvisionOrganizationAuthorization::class)->handle($organization);
        });
    }

    /**
     * Assign a specific user as the organization owner.
     */
    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => $owner->id,
        ]);
    }

    /**
     * Indicate that the organization has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
