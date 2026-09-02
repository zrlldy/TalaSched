<?php

namespace App\Http\Controllers\Subscriptions;

use App\Enums\CapabilityKey;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Subscriptions\EntitlementService;
use App\Subscriptions\UsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        private EntitlementService $entitlements,
        private UsageService $usage,
    ) {}

    public function show(Request $request, Organization $currentOrganization): Response
    {
        Gate::forUser($request->user())->authorize('update', $currentOrganization);

        return Inertia::render('subscriptions/Show', [
            'subscription' => $this->subscription($currentOrganization),
            'features' => $this->features($currentOrganization),
            'limits' => $this->limits($currentOrganization),
        ]);
    }

    /**
     * @return array{plan: array{code: string, name: string}, status: string, status_label: string, period_starts_at: string|null, period_ends_at: string|null, trial_ends_at: string|null, grace_ends_at: string|null}|null
     */
    private function subscription(Organization $organization): ?array
    {
        $subscription = DB::table('organization_subscriptions as subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.organization_id', $organization->getKey())
            ->latest('subscriptions.id')
            ->first([
                'plans.code as plan_code',
                'plans.name as plan_name',
                'subscriptions.status',
                'subscriptions.period_starts_at',
                'subscriptions.period_ends_at',
                'subscriptions.trial_ends_at',
                'subscriptions.grace_ends_at',
            ]);

        if ($subscription === null) {
            return null;
        }

        return [
            'plan' => [
                'code' => (string) $subscription->plan_code,
                'name' => (string) $subscription->plan_name,
            ],
            'status' => (string) $subscription->status,
            'status_label' => Str::headline((string) $subscription->status),
            'period_starts_at' => $this->timestamp($subscription->period_starts_at),
            'period_ends_at' => $this->timestamp($subscription->period_ends_at),
            'trial_ends_at' => $this->timestamp($subscription->trial_ends_at),
            'grace_ends_at' => $this->timestamp($subscription->grace_ends_at),
        ];
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool}>
     */
    private function features(Organization $organization): array
    {
        $values = $this->entitlements->values($organization);
        $features = [];

        foreach (CapabilityKey::cases() as $capability) {
            if ($capability->valueType() !== 'boolean') {
                continue;
            }

            $features[] = [
                'key' => $capability->value,
                'label' => $capability->label(),
                'enabled' => $values[$capability->value] === true,
            ];
        }

        return $features;
    }

    /**
     * @return list<array{key: string, label: string, limit: int|null, current: int|null, remaining: int|null}>
     */
    private function limits(Organization $organization): array
    {
        $limits = [];

        foreach ([
            CapabilityKey::MaxMembers,
            CapabilityKey::MaxActiveTimetables,
        ] as $capability) {
            $limit = $this->entitlements->limit($organization, $capability);
            $current = $limit === null ? null : $this->usage->current($organization, $capability);

            $limits[] = [
                'key' => $capability->value,
                'label' => $capability->label(),
                'limit' => $limit,
                'current' => $current,
                'remaining' => $limit === null || $current === null ? null : max(0, $limit - $current),
            ];
        }

        return $limits;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toISOString();
    }
}
