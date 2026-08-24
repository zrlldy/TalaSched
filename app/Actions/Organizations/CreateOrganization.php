<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Subscriptions\ProvisionOrganizationSubscription;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(
        private ProvisionOrganizationAuthorization $provisionAuthorization,
        private ProvisionOrganizationSubscription $provisionSubscription,
    ) {}

    /**
     * Create a new organization and add the user as owner.
     */
    public function handle(User $user, string $name): Organization
    {
        return DB::transaction(function () use ($user, $name) {
            $organization = Organization::create([
                'name' => $name,
                'owner_user_id' => $user->id,
            ]);

            $organization->memberships()->create([
                'user_id' => $user->id,
                'role' => OrganizationRole::Owner,
            ]);

            $this->provisionSubscription->handle($organization);
            $this->provisionAuthorization->handle($organization);

            $user->switchOrganization($organization);

            return $organization;
        });
    }
}
