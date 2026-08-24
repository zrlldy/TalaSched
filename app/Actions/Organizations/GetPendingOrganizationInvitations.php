<?php

namespace App\Actions\Organizations;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Collection;

class GetPendingOrganizationInvitations
{
    /**
     * Get active invitations addressed to the user.
     *
     * @return Collection<int, array{id: string, inviterName: string, organization: array{name: string, slug: string}}>
     */
    public function handle(User $user): Collection
    {
        return OrganizationInvitation::query()
            ->with(['inviter', 'organization'])
            ->where('email_normalized', OrganizationInvitation::normalizeEmail($user->email))
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->public_id,
                'inviterName' => $invitation->inviter->name,
                'organization' => [
                    'name' => $invitation->organization->name,
                    'slug' => $invitation->organization->slug,
                ],
            ]);
    }
}
