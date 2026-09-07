<?php

namespace App\Http\Middleware;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\ExcelTemplate;
use App\Models\FacultyProfile;
use App\Models\Subject;
use App\Subscriptions\CapabilityGuard;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private CapabilityGuard $capabilities) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentOrganization' => fn () => $user?->currentOrganization ? $user->toUserOrganization($user->currentOrganization) : null,
            'organizations' => fn () => $user?->toUserOrganizations(includeCurrent: true) ?? [],
            'organizationTimezone' => fn (): ?string => $user?->currentOrganization?->timezone,
            'entitlements' => fn (): array => $user?->currentOrganization
                ? $this->capabilities->values($user->currentOrganization)
                : [],
            'canManageSubscription' => fn (): bool => $user?->currentOrganization !== null
                && $user->can('update', $user->currentOrganization),
            'canViewAudit' => fn (): bool => $user?->currentOrganization !== null
                && $user->can('viewAny', [AuditEvent::class, $user->currentOrganization]),
            'canManageTemplates' => fn (): bool => $user?->currentOrganization !== null
                && $user->can('viewAny', [ExcelTemplate::class, $user->currentOrganization]),
            'workspacePermissions' => fn (): array => [
                'academic' => $user?->currentOrganization !== null && $user->can('create', [AcademicYear::class, $user->currentOrganization]),
                'resources' => $user?->currentOrganization !== null && $user->can('create', [FacultyProfile::class, $user->currentOrganization]),
                'catalog' => $user?->currentOrganization !== null && $user->can('create', [Subject::class, $user->currentOrganization]),
            ],
        ];
    }
}
