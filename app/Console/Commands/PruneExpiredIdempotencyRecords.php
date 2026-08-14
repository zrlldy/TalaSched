<?php

namespace App\Console\Commands;

use App\Models\IdempotencyRecord;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('idempotency:prune')]
#[Description('Delete expired tenant idempotency records')]
class PruneExpiredIdempotencyRecords extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TenantContext $tenantContext): int
    {
        $tenantContext->forEachOrganization(function (Organization $organization): void {
            IdempotencyRecord::query()
                ->where('organization_id', $organization->id)
                ->where('expires_at', '<', now())
                ->delete();
        });

        return self::SUCCESS;
    }
}
