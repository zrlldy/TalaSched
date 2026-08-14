<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invitations:prune')]
#[Description('Delete expired tenant organization invitations')]
class PruneExpiredOrganizationInvitations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TenantContext $tenantContext): int
    {
        $tenantContext->forEachOrganization(function (Organization $organization): void {
            OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->delete();
        });

        return self::SUCCESS;
    }
}
