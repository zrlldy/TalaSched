<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'membership_id', 'role_id', 'academic_unit_id'])]
class MembershipRoleAssignment extends Model
{
    /**
     * Bootstrap normalized assignment invariants.
     */
    protected static function booted(): void
    {
        static::saving(function (MembershipRoleAssignment $assignment): void {
            $roleCode = Role::query()->whereKey($assignment->role_id)->value('code');

            if ($roleCode !== OrganizationRole::Owner->value) {
                return;
            }

            $ownerUserId = Organization::query()
                ->whereKey($assignment->organization_id)
                ->value('owner_user_id');
            $membershipUserId = Membership::query()
                ->whereKey($assignment->membership_id)
                ->value('user_id');

            if ((int) $membershipUserId !== (int) $ownerUserId) {
                throw new LogicException('Only the explicit organization owner may receive the owner normalized role.');
            }
        });
    }

    /**
     * Get the organization that owns the assignment.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the membership receiving the assignment.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Get the role being assigned.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the optional academic unit scope.
     *
     * @return BelongsTo<AcademicUnit, $this>
     */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }
}
