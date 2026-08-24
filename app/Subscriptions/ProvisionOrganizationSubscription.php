<?php

namespace App\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

class ProvisionOrganizationSubscription
{
    private const STARTER_PLAN_CODE = 'starter';

    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Organization $organization): void
    {
        $this->tenantContext->run($organization, function () use ($organization): void {
            DB::transaction(function () use ($organization): void {
                Organization::query()
                    ->whereKey($organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (DB::table('organization_subscriptions')
                    ->where('organization_id', $organization->getKey())
                    ->exists()) {
                    return;
                }

                $planId = DB::table('plans')
                    ->where('code', self::STARTER_PLAN_CODE)
                    ->where('is_active', true)
                    ->value('id');

                if (! is_numeric($planId)) {
                    throw new LogicException('The subscription catalog must be seeded before creating organizations.');
                }

                $timestamp = now();

                DB::table('organization_subscriptions')->insert([
                    'organization_id' => $organization->getKey(),
                    'plan_id' => (int) $planId,
                    'status' => SubscriptionStatus::Active->value,
                    'period_starts_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }, attempts: 3);
        });
    }
}
