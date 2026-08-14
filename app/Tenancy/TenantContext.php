<?php

namespace App\Tenancy;

use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    public const string ContextAttribute = 'organization_id';

    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_organization_id', ?, false)", [(string) $organization->id]);
        }

        $this->organization = $organization;
        Context::add(self::ContextAttribute, $organization->public_id);
    }

    public function clear(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("select set_config('app.current_organization_id', '', false)");
        }

        $this->organization = null;
        Context::forget(self::ContextAttribute);
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    /**
     * Run work under an organization and restore the previous context afterwards.
     *
     * @template TResult
     *
     * @param  Closure(Organization): TResult  $callback
     * @return TResult
     */
    public function run(Organization $organization, Closure $callback): mixed
    {
        $previousOrganization = $this->organization;

        $this->set($organization);

        try {
            return $callback($organization);
        } finally {
            if ($previousOrganization) {
                $this->set($previousOrganization);
            } else {
                $this->clear();
            }
        }
    }

    /**
     * Resolve a public organization identifier and run work under its context.
     *
     * @template TResult
     *
     * @param  Closure(Organization): TResult  $callback
     * @return TResult
     */
    public function runByPublicId(string $organizationPublicId, Closure $callback): mixed
    {
        $organization = Organization::query()
            ->where('public_id', $organizationPublicId)
            ->firstOrFail();

        return $this->run($organization, $callback);
    }

    /**
     * Run work once for every active organization under an isolated context.
     *
     * @param  Closure(Organization): void  $callback
     */
    public function forEachOrganization(Closure $callback): void
    {
        Organization::query()
            ->select(['id', 'public_id'])
            ->orderBy('id')
            ->eachById(fn (Organization $organization) => $this->run($organization, $callback));
    }
}
