<?php

namespace App\Providers;

use App\Authorization\OrganizationPermissionResolver;
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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
}
