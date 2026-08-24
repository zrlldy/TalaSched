<?php

namespace App\Models;

use App\Actions\Organizations\ProvisionOrganizationAuthorization;
use App\Concerns\HasPublicId;
use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property int $user_id
 * @property OrganizationRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 */
#[Fillable(['public_id', 'organization_id', 'user_id', 'role'])]
class Membership extends Pivot
{
    use HasPublicId;

    /**
     * Bootstrap membership synchronization.
     */
    protected static function booted(): void
    {
        static::saving(function (Membership $membership): void {
            if ($membership->role !== OrganizationRole::Owner) {
                return;
            }

            $ownerUserId = Organization::query()
                ->whereKey($membership->organization_id)
                ->value('owner_user_id');

            if ((int) $membership->user_id !== (int) $ownerUserId) {
                throw new LogicException('Only the explicit organization owner may hold the owner membership role.');
            }
        });

        static::deleting(function (Membership $membership): void {
            $ownerUserId = Organization::query()
                ->whereKey($membership->organization_id)
                ->value('owner_user_id');

            if ($membership->user_id === $ownerUserId) {
                throw new LogicException('The organization owner membership cannot be removed.');
            }
        });

        static::saved(function (Membership $membership): void {
            if (! $membership->wasRecentlyCreated && ! $membership->wasChanged('role')) {
                return;
            }

            app(ProvisionOrganizationAuthorization::class)->handle($membership->organization()->firstOrFail());
        });
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'organization_members';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the organization that the membership belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user that belongs to this membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the normalized roles assigned to this membership.
     *
     * @return HasMany<MembershipRoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(MembershipRoleAssignment::class, 'membership_id');
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
        ];
    }
}
