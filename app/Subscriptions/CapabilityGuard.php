<?php

namespace App\Subscriptions;

use App\Enums\CapabilityKey;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class CapabilityGuard
{
    public function __construct(private EntitlementService $entitlements) {}

    public function allows(Organization $organization, CapabilityKey $capability): bool
    {
        return $this->entitlements->allows($organization, $capability);
    }

    /**
     * @return array<string, bool|int|null>
     */
    public function values(Organization $organization): array
    {
        return $this->entitlements->values($organization);
    }

    public function limit(Organization $organization, CapabilityKey $capability): ?int
    {
        return $this->entitlements->limit($organization, $capability);
    }

    public function assertEnabled(Organization $organization, CapabilityKey $capability): void
    {
        if (! $this->allows($organization, $capability)) {
            throw new AuthorizationException("The organization does not have the {$capability->label()} capability enabled.");
        }
    }

    public function hasCapacity(
        Organization $organization,
        CapabilityKey $capability,
        int $current,
        int $additional = 1,
    ): bool {
        $this->validateQuantities($current, $additional);
        $limit = $this->entitlements->limit($organization, $capability);

        if ($limit === null || $current > $limit) {
            return false;
        }

        return $additional <= $limit - $current;
    }

    public function assertCanConsume(
        Organization $organization,
        CapabilityKey $capability,
        int $current,
        int $additional = 1,
    ): void {
        $this->validateQuantities($current, $additional);
        $limit = $this->entitlements->limit($organization, $capability);

        if ($limit === null) {
            throw new AuthorizationException("The organization does not have the {$capability->label()} limit enabled.");
        }

        if ($current > $limit || $additional > $limit - $current) {
            throw new AuthorizationException("The organization has reached its {$capability->label()} limit.");
        }
    }

    private function validateQuantities(int $current, int $additional): void
    {
        if ($current < 0 || $additional < 0) {
            throw new InvalidArgumentException('Capability usage quantities cannot be negative.');
        }
    }
}
