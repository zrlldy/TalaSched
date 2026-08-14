<?php

namespace App\Subscriptions;

use App\Enums\CapabilityKey;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

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
            ->whereIn('status', ['trialing', 'active'])
            ->where(fn ($query) => $query->whereNull('period_ends_at')->orWhere('period_ends_at', '>', now()))
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return null;
        }

        $planValue = DB::table('plan_capability_values')
            ->where('plan_id', $subscription->plan_id)
            ->where('capability_id', $capabilityRecord->id)
            ->first();

        return $planValue === null ? null : $this->typedValue($capabilityRecord->value_type, $planValue);
    }

    private function typedValue(string $type, object $record): bool|int|null
    {
        return match ($type) {
            'boolean' => $record->boolean_value === null ? null : (bool) $record->boolean_value,
            'integer' => $record->integer_value === null ? null : (int) $record->integer_value,
            default => null,
        };
    }
}
