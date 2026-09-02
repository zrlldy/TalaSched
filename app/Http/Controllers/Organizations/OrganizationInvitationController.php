<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Audit\AuditLogger;
use App\Enums\CapabilityKey;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\CreateOrganizationInvitationRequest;
use App\Http\Requests\Organizations\RespondToOrganizationInvitationRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Notifications\Organizations\OrganizationInvitation as OrganizationInvitationNotification;
use App\Subscriptions\UsageService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrganizationInvitationController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private ProvisionOrganizationAuthorization $provisionAuthorization,
        private TenantContext $tenantContext,
        private UsageService $usage,
    ) {}

    /**
     * Store a newly created invitation.
     */
    public function store(CreateOrganizationInvitationRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('create', [OrganizationInvitation::class, $organization]);

        $invitation = DB::transaction(function () use ($organization, $request): OrganizationInvitation {
            $invitation = $organization->invitations()->create([
                'email' => $request->validated('email'),
                'role' => OrganizationRole::from($request->validated('role')),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(3),
            ]);

            $this->auditLogger->record(
                action: 'organization.invitation_created',
                organization: $organization,
                actor: $request->user(),
                subject: $invitation,
                after: [
                    'role' => $invitation->role->value,
                    'status' => 'pending',
                ],
            );

            return $invitation;
        });

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification($invitation, $invitation->plainTextToken()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Request $request, Organization $organization, OrganizationInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->organization_id === $organization->id, 404);

        Gate::authorize('delete', $invitation);

        DB::transaction(function () use ($invitation, $organization, $request): void {
            $this->auditLogger->record(
                action: 'organization.invitation_cancelled',
                organization: $organization,
                actor: $request->user(),
                subject: $invitation,
                before: [
                    'role' => $invitation->role->value,
                    'status' => 'pending',
                ],
                after: ['status' => 'cancelled'],
            );

            $invitation->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('organizations.edit', ['organization' => $organization->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToOrganizationInvitationRequest $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        $organization = Organization::query()->findOrFail($invitation->organization_id);

        $this->tenantContext->run($organization, function (Organization $organization) use ($invitation, $user): void {
            DB::transaction(function () use ($invitation, $organization, $user): void {
                $invitation = OrganizationInvitation::query()
                    ->whereKey($invitation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensurePending($invitation);

                $membership = Membership::query()
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($membership === null) {
                    $this->usage->reserveIfConfigured($organization, CapabilityKey::MaxMembers);

                    $membership = $organization->memberships()->create([
                        'user_id' => $user->id,
                        'role' => $invitation->role,
                    ]);
                }

                $this->provisionAuthorization->handle($organization);

                $invitation->forceFill(['accepted_at' => now()])->save();

                $this->auditLogger->record(
                    action: 'organization.invitation_accepted',
                    organization: $organization,
                    actor: $user,
                    subject: $invitation,
                    after: [
                        'membership_public_id' => $membership->public_id,
                        'role' => $membership->role->value,
                        'status' => 'accepted',
                    ],
                );

                $user->switchOrganization($organization);
            });
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToOrganizationInvitationRequest $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $organization = Organization::query()->findOrFail($invitation->organization_id);

        $this->tenantContext->run($organization, function (Organization $organization) use ($invitation, $request): void {
            DB::transaction(function () use ($invitation, $organization, $request): void {
                $invitation = OrganizationInvitation::query()
                    ->whereKey($invitation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensurePending($invitation);

                $this->auditLogger->record(
                    action: 'organization.invitation_declined',
                    organization: $organization,
                    actor: $request->user(),
                    subject: $invitation,
                    before: [
                        'role' => $invitation->role->value,
                        'status' => 'pending',
                    ],
                    after: ['status' => 'declined'],
                );

                $invitation->delete();
            });
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        $organization = $request->user()->currentOrganization;

        return $organization
            ? to_route('dashboard', ['current_organization' => $organization->slug])
            : to_route('organizations.index');
    }

    private function ensurePending(OrganizationInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has already been accepted.'),
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has expired.'),
            ]);
        }
    }
}
