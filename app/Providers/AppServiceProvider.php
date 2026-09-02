<?php

namespace App\Providers;

use App\Authorization\OrganizationPermissionResolver;
use App\Models\Organization;
use App\Scheduling\ConstraintRegistry;
use App\Scheduling\Constraints\AcademicCalendarConstraintHandler;
use App\Scheduling\Constraints\FacultyLoadConstraintHandler;
use App\Scheduling\Constraints\GranularityConstraintHandler;
use App\Scheduling\Constraints\InstructorEligibilityConstraintHandler;
use App\Scheduling\Constraints\OfferingFulfillmentConstraintHandler;
use App\Scheduling\Constraints\OfferingGroupConstraintHandler;
use App\Scheduling\Constraints\OrganizationBlockedTimeConstraintHandler;
use App\Scheduling\Constraints\ResourceActiveConstraintHandler;
use App\Scheduling\Constraints\ResourceAvailabilityConstraintHandler;
use App\Scheduling\Constraints\ResourceOverlapConstraintHandler;
use App\Scheduling\Constraints\ResourceRoleConstraintHandler;
use App\Scheduling\Constraints\RoomCapacityConstraintHandler;
use App\Scheduling\Constraints\RoomFeatureConstraintHandler;
use App\Scheduling\Constraints\RoomTypeConstraintHandler;
use App\Scheduling\Constraints\VersionEditableConstraintHandler;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->scoped(
            OrganizationPermissionResolver::class,
            fn (Application $app): OrganizationPermissionResolver => new OrganizationPermissionResolver($app->make(TenantContext::class)),
        );
        $this->app->singleton(
            ConstraintRegistry::class,
            fn (Application $app): ConstraintRegistry => new ConstraintRegistry([
                $app->make(VersionEditableConstraintHandler::class),
                $app->make(GranularityConstraintHandler::class),
                $app->make(AcademicCalendarConstraintHandler::class),
                $app->make(ResourceRoleConstraintHandler::class),
                $app->make(ResourceActiveConstraintHandler::class),
                $app->make(InstructorEligibilityConstraintHandler::class),
                $app->make(OfferingGroupConstraintHandler::class),
                $app->make(ResourceOverlapConstraintHandler::class),
                $app->make(ResourceAvailabilityConstraintHandler::class),
                $app->make(RoomCapacityConstraintHandler::class),
                $app->make(RoomTypeConstraintHandler::class),
                $app->make(RoomFeatureConstraintHandler::class),
                $app->make(FacultyLoadConstraintHandler::class),
                $app->make(OfferingFulfillmentConstraintHandler::class),
                $app->make(OrganizationBlockedTimeConstraintHandler::class),
            ]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure organization-scoped limits for expensive or security-sensitive commands.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('organization-invitations', fn (Request $request): Limit => $this->organizationLimit(
            $request,
            'organization-invitations',
            10,
        ));

        RateLimiter::for('organization-invitation-responses', fn (Request $request): Limit => $this->organizationLimit(
            $request,
            'organization-invitation-responses',
            10,
        ));

        RateLimiter::for('schedule-validation', fn (Request $request): Limit => $this->organizationLimit(
            $request,
            'schedule-validation',
            30,
        ));

        RateLimiter::for('uploads', fn (Request $request): Limit => $this->organizationLimit(
            $request,
            'uploads',
            10,
        ));
    }

    /**
     * Limit an authenticated actor within the organization addressed by the route.
     */
    private function organizationLimit(Request $request, string $name, int $maxAttempts): Limit
    {
        $organization = $request->route('organization') ?? $request->route('current_organization');
        $organizationIdentifier = match (true) {
            $organization instanceof Organization => $organization->public_id,
            is_string($organization) => $organization,
            default => 'unscoped',
        };
        $actor = $request->user();
        $actorIdentifier = $actor === null ? $request->ip() : (string) $actor->getAuthIdentifier();

        return Limit::perMinute($maxAttempts)->by(implode('|', [
            $name,
            $organizationIdentifier,
            $actorIdentifier,
        ]));
    }
}
