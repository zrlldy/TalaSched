<?php

namespace App\Subscriptions;

use App\Enums\CapabilityKey;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use stdClass;

class UsageService
{
    private const CAPACITY_PERIOD_START = '1900-01-01';

    private const CAPACITY_PERIOD_END = '9999-12-31';

    public function __construct(
        private CapabilityGuard $capabilities,
        private TenantContext $tenantContext,
    ) {}

    public function current(Organization $organization, CapabilityKey $capability): int
    {
        return $this->tenantContext->run($organization, function () use ($organization, $capability): int {
            $capabilityId = $this->capabilityId($capability);
            $quantity = DB::table('usage_counters')
                ->where('organization_id', $organization->getKey())
                ->where('capability_id', $capabilityId)
                ->where('period_starts_on', self::CAPACITY_PERIOD_START)
                ->value('quantity');

            return $quantity === null
                ? $this->initialQuantity($organization, $capability)
                : (int) $quantity;
        });
    }

    public function reserveIfConfigured(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity = 1,
    ): ?int {
        $this->validateQuantity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $capability, $quantity): ?int {
            if ($this->capabilities->limit($organization, $capability) === null) {
                return null;
            }

            return $this->reserveWithinContext($organization, $capability, $quantity);
        });
    }

    public function reserve(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity = 1,
    ): int {
        $this->validateQuantity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $capability, $quantity): int {
            return $this->reserveWithinContext($organization, $capability, $quantity);
        });
    }

    public function releaseIfReserved(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity = 1,
    ): ?int {
        $this->validateQuantity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $capability, $quantity): ?int {
            if (! $this->counterExists($organization, $capability)) {
                return null;
            }

            return $this->releaseWithinContext($organization, $capability, $quantity);
        });
    }

    public function release(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity = 1,
    ): int {
        $this->validateQuantity($quantity);

        return $this->tenantContext->run($organization, function () use ($organization, $capability, $quantity): int {
            return $this->releaseWithinContext($organization, $capability, $quantity);
        });
    }

    private function reserveWithinContext(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity,
    ): int {
        return DB::transaction(function () use ($organization, $capability, $quantity): int {
            $counter = $this->lockCounter($organization, $capability);
            $current = (int) $counter->quantity;

            $this->capabilities->assertCanConsume($organization, $capability, $current, $quantity);

            $newQuantity = $current + $quantity;
            DB::table('usage_counters')
                ->where('id', $counter->id)
                ->update([
                    'quantity' => $newQuantity,
                    'updated_at' => now(),
                ]);

            return $newQuantity;
        }, attempts: 3);
    }

    private function releaseWithinContext(
        Organization $organization,
        CapabilityKey $capability,
        int $quantity,
    ): int {
        return DB::transaction(function () use ($organization, $capability, $quantity): int {
            $counter = $this->lockCounter($organization, $capability);
            $current = (int) $counter->quantity;

            if ($quantity > $current) {
                throw new DomainException('Usage cannot be released below zero.');
            }

            $newQuantity = $current - $quantity;
            DB::table('usage_counters')
                ->where('id', $counter->id)
                ->update([
                    'quantity' => $newQuantity,
                    'updated_at' => now(),
                ]);

            return $newQuantity;
        }, attempts: 3);
    }

    private function counterExists(Organization $organization, CapabilityKey $capability): bool
    {
        $capabilityId = $this->registeredCapabilityId($capability);

        if ($capabilityId === null) {
            return false;
        }

        return DB::table('usage_counters')
            ->where('organization_id', $organization->getKey())
            ->where('capability_id', $capabilityId)
            ->where('period_starts_on', self::CAPACITY_PERIOD_START)
            ->exists();
    }

    private function lockCounter(Organization $organization, CapabilityKey $capability): stdClass
    {
        $capabilityId = $this->capabilityId($capability);
        DB::table('usage_counters')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'capability_id' => $capabilityId,
            'period_starts_on' => self::CAPACITY_PERIOD_START,
            'period_ends_on' => self::CAPACITY_PERIOD_END,
            'quantity' => $this->initialQuantity($organization, $capability),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('usage_counters')
            ->where('organization_id', $organization->getKey())
            ->where('capability_id', $capabilityId)
            ->where('period_starts_on', self::CAPACITY_PERIOD_START)
            ->lockForUpdate()
            ->first();

        if (! $counter instanceof stdClass) {
            throw new LogicException('The usage counter could not be locked.');
        }

        return $counter;
    }

    private function capabilityId(CapabilityKey $capability): int
    {
        $capabilityId = $this->registeredCapabilityId($capability);

        if ($capabilityId === null) {
            throw new LogicException("Capability [{$capability->value}] is not registered.");
        }

        return $capabilityId;
    }

    private function registeredCapabilityId(CapabilityKey $capability): ?int
    {
        $capabilityId = DB::table('capabilities')
            ->where('code', $capability->value)
            ->value('id');

        return is_numeric($capabilityId) ? (int) $capabilityId : null;
    }

    private function initialQuantity(Organization $organization, CapabilityKey $capability): int
    {
        return match ($capability) {
            CapabilityKey::MaxMembers => (int) DB::table('organization_members')
                ->where('organization_id', $organization->getKey())
                ->count(),
            CapabilityKey::MaxActiveTimetables => (int) DB::table('timetables')
                ->where('organization_id', $organization->getKey())
                ->count(),
            default => 0,
        };
    }

    private function validateQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Usage quantities must be positive.');
        }
    }
}
