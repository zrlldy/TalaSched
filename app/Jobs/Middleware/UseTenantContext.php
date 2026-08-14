<?php

namespace App\Jobs\Middleware;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Container\Container;

class UseTenantContext
{
    public function __construct(public string $organizationPublicId) {}

    /**
     * Process the queued job.
     *
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        $tenantContext = Container::getInstance()->make(TenantContext::class);

        $tenantContext->runByPublicId(
            $this->organizationPublicId,
            function (Organization $organization) use ($job, $next): void {
                $next($job);
            },
        );
    }
}
