<?php

use App\Jobs\Middleware\UseTenantContext;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;

test('tenant execution restores nested contexts and clears them after failure', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();
    $tenantContext = app(TenantContext::class);

    $tenantContext->run($firstOrganization, function (Organization $activeOrganization) use ($firstOrganization, $secondOrganization, $tenantContext): void {
        expect($activeOrganization->is($firstOrganization))->toBeTrue()
            ->and($tenantContext->organization()?->is($activeOrganization))->toBeTrue()
            ->and(Context::get(TenantContext::ContextAttribute))->toBe($firstOrganization->public_id);

        $tenantContext->run($secondOrganization, function (Organization $nestedOrganization) use ($secondOrganization, $tenantContext): void {
            expect($nestedOrganization->is($secondOrganization))->toBeTrue()
                ->and($tenantContext->organization()?->is($secondOrganization))->toBeTrue()
                ->and(Context::get(TenantContext::ContextAttribute))->toBe($secondOrganization->public_id);
        });

        expect($tenantContext->organization()?->is($activeOrganization))->toBeTrue()
            ->and(Context::get(TenantContext::ContextAttribute))->toBe($firstOrganization->public_id);
    });

    expect($tenantContext->organization())->toBeNull()
        ->and(Context::has(TenantContext::ContextAttribute))->toBeFalse();

    expect(fn () => $tenantContext->run(
        $firstOrganization,
        fn (Organization $organization) => throw new RuntimeException("Failed for {$organization->public_id}"),
    ))->toThrow(RuntimeException::class);

    expect($tenantContext->organization())->toBeNull();
});

test('tenant iteration establishes and clears context for every organization', function () {
    $organizations = Organization::factory()->count(2)->create();
    $tenantContext = app(TenantContext::class);
    $visitedOrganizationIds = [];

    $tenantContext->forEachOrganization(function (Organization $organization) use (&$visitedOrganizationIds, $tenantContext): void {
        expect($tenantContext->organization()?->is($organization))->toBeTrue();
        $visitedOrganizationIds[] = $organization->public_id;
    });

    expect($visitedOrganizationIds)->toBe($organizations->pluck('public_id')->all())
        ->and($tenantContext->organization())->toBeNull();
});

test('queued tenant middleware resolves public identity and clears context', function () {
    $organization = Organization::factory()->create();
    TenantContextProbeJob::$observedOrganizationPublicId = null;

    Bus::dispatchSync(new TenantContextProbeJob($organization->public_id));

    expect(TenantContextProbeJob::$observedOrganizationPublicId)->toBe($organization->public_id)
        ->and(app(TenantContext::class)->organization())->toBeNull();
});

final class TenantContextProbeJob implements ShouldQueue
{
    use Queueable;

    public static ?string $observedOrganizationPublicId = null;

    public function __construct(public string $organizationPublicId) {}

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new UseTenantContext($this->organizationPublicId)];
    }

    public function handle(TenantContext $tenantContext): void
    {
        self::$observedOrganizationPublicId = $tenantContext->organization()?->public_id;
    }
}
