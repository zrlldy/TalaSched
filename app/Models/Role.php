<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'code', 'name', 'is_system'])]
class Role extends Model
{
    /**
     * Get the public route key for the role.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Get the organization that owns the role.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the permissions granted by the role.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withPivot('organization_id');
    }

    /**
     * Get the membership assignments that use the role.
     *
     * @return HasMany<MembershipRoleAssignment, $this>
     */
    public function membershipAssignments(): HasMany
    {
        return $this->hasMany(MembershipRoleAssignment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
