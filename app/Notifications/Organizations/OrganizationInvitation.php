<?php

namespace App\Notifications\Organizations;

use App\Jobs\Middleware\UseTenantContext;
use App\Models\OrganizationInvitation as OrganizationInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public string $organizationPublicId;

    /**
     * Create a new notification instance.
     */
    public function __construct(public OrganizationInvitationModel $invitation, public string $token)
    {
        $this->organizationPublicId = $invitation->organization->public_id;
    }

    /**
     * Get the middleware the notification job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return [new UseTenantContext($this->organizationPublicId)];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization;
        $inviter = $this->invitation->inviter;

        return (new MailMessage)
            ->subject(__("You've been invited to join :organizationName", ['organizationName' => $organization->name]))
            ->line(__(':inviterName has invited you to join the :organizationName organization.', [
                'inviterName' => $inviter->name,
                'organizationName' => $organization->name,
            ]))
            ->line(__('Log in and visit your dashboard to accept or decline this invitation.'))
            ->action(
                __('Log in'),
                route('login', ['invitation' => $this->token]),
            );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'organization_id' => $this->organizationPublicId,
            'organization_name' => $this->invitation->organization->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
