<?php

namespace App\Subscriptions;

use App\Enums\CapabilityKey;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class EntitlementService
{
    public function allows(Organization $organization, CapabilityKey $capability): bool
    {
        return (bool) ($this->value($organization, $capability) ?? false);
    }

    public function limit(Organization $organization, CapabilityKey $capability): ?int
    {
        $value = $this->value($organization, $capability);

        return is_int($value) ? $value : null;
    }

    /**
     * Resolve every catalog capability for shared organization context props.
     *
     * @return array<string, bool|int|null>
     */
    public function values(Organization $organization): array
    {
        $capabilityCodes = array_map(
            static fn (CapabilityKey $capability): string => $capability->value,
            CapabilityKey::cases(),
        );
        $capabilityRecords = DB::table('capabilities')
            ->whereIn('code', $capabilityCodes)
            ->get()
            ->keyBy('code');
        $values = [];

        foreach ($capabilityCodes as $capabilityCode) {
            $values[$capabilityCode] = null;
        }

        if ($capabilityRecords->isEmpty()) {
            return $values;
        }

        $capabilityIds = $capabilityRecords
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $overrides = DB::table('organization_entitlement_overrides')
            ->where('organization_id', $organization->id)
            ->whereIn('capability_id', $capabilityIds)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('capability_id');
        $subscription = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();
        $planValues = null;

        if ($subscription instanceof stdClass && $this->subscriptionIsUsable($subscription)) {
            $planValues = DB::table('plan_capability_values')
                ->where('plan_id', $subscription->plan_id)
                ->whereIn('capability_id', $capabilityIds)
                ->get()
                ->keyBy('capability_id');
        }

        foreach (CapabilityKey::cases() as $capability) {
            $capabilityRecord = $capabilityRecords->get($capability->value);

            if (! $capabilityRecord instanceof stdClass) {
                continue;
            }

            $override = $overrides->get($capabilityRecord->id);

            if ($override instanceof stdClass) {
                $values[$capability->value] = $this->typedValue($capabilityRecord->value_type, $override);

                continue;
            }

            $planValue = $planValues?->get($capabilityRecord->id);

            if ($planValue instanceof stdClass) {
                $values[$capability->value] = $this->typedValue($capabilityRecord->value_type, $planValue);
            }
        }

        return $values;
    }

    public function value(Organization $organization, CapabilityKey $capability): bool|int|null
    {
        $capabilityRecord = DB::table('capabilities')->where('code', $capability->value)->first();

        if ($capabilityRecord === null) {
            return null;
        }

        $override = DB::table('organization_entitlement_overrides')
            ->where('organization_id', $organization->id)
            ->where('capability_id', $capabilityRecord->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($override !== null) {
            return $this->typedValue($capabilityRecord->value_type, $override);
        }

        $subscription = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->first();

        if ($subscription === null || ! $this->subscriptionIsUsable($subscription)) {
            return null;
        }

        $planValue = DB::table('plan_capability_values')
            ->where('plan_id', $subscription->plan_id)
            ->where('capability_id', $capabilityRecord->id)
            ->first();

        return $planValue === null ? null : $this->typedValue($capabilityRecord->value_type, $planValue);
    }

    public function status(Organization $organization): ?SubscriptionStatus
    {
        $status = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->id)
            ->latest('id')
            ->value('status');

        return is_string($status) ? SubscriptionStatus::tryFrom($status) : null;
    }

    private function subscriptionIsUsable(stdClass $subscription): bool
    {
        $status = SubscriptionStatus::tryFrom((string) $subscription->status);

        if ($status === null) {
            return false;
        }

        return match ($status) {
            SubscriptionStatus::Trialing => $this->isFuture($subscription->trial_ends_at ?? $subscription->period_ends_at),
            SubscriptionStatus::Active, SubscriptionStatus::Canceled => $this->isFuture($subscription->period_ends_at),
            SubscriptionStatus::GracePeriod, SubscriptionStatus::PastDue => $this->isFuture($subscription->grace_ends_at),
            SubscriptionStatus::Expired => false,
        };
    }

    private function isFuture(mixed $timestamp): bool
    {
        if ($timestamp === null) {
            return true;
        }

        return Carbon::parse((string) $timestamp)->isFuture();
    }

    private function typedValue(string $type, stdClass $record): bool|int|null
    {
        return match ($type) {
            'boolean' => $record->boolean_value === null ? null : (bool) $record->boolean_value,
            'integer' => $record->integer_value === null ? null : (int) $record->integer_value,
            default => null,
        };
    }
}
