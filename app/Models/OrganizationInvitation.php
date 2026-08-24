<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\OrganizationRole;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property string $token_hash
 * @property int $organization_id
 * @property string $email
 * @property string $email_normalized
 * @property OrganizationRole $role
 * @property int $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $inviter
 */
#[Fillable(['organization_id', 'email', 'role', 'invited_by', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory, HasPublicId;

    private ?string $plainTextToken = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (OrganizationInvitation $invitation): void {
            if ($invitation->role === OrganizationRole::Owner) {
                throw new LogicException('Organization owner status must be transferred explicitly.');
            }
        });

        static::creating(function (OrganizationInvitation $invitation): void {
            if ($invitation->plainTextToken === null && empty($invitation->token_hash)) {
                $invitation->plainTextToken = Str::random(64);
                $invitation->token_hash = static::hashToken($invitation->plainTextToken);
            }

            if (empty($invitation->email_normalized) && is_string($invitation->email)) {
                $invitation->email_normalized = static::normalizeEmail($invitation->email);
            }
        });

        static::updating(function (OrganizationInvitation $invitation): void {
            if ($invitation->isDirty('email')) {
                $invitation->email_normalized = static::normalizeEmail((string) $invitation->email);
            }
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function findByToken(string $token): ?static
    {
        return static::query()
            ->where('token_hash', static::hashToken($token))
            ->first();
    }

    public function plainTextToken(): string
    {
        if ($this->plainTextToken === null) {
            throw new LogicException('The invitation plaintext token is only available when the invitation is created.');
        }

        return $this->plainTextToken;
    }

    /**
     * Get the organization that the invitation belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Determine if the invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Determine if the invitation is pending.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    /**
     * Determine if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
