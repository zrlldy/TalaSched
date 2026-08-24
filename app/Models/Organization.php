<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueOrganizationSlugs;
use App\Concerns\HasPublicId;
use App\Enums\OrganizationRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $owner_user_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, OrganizationInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 */
#[Fillable(['name', 'slug', 'owner_user_id', 'timezone', 'locale', 'scheduling_granularity', 'status'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use GeneratesUniqueOrganizationSlugs, HasFactory, HasPublicId, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Organization $organization): void {
            if (! $organization->exists || ! $organization->isDirty('owner_user_id')) {
                return;
            }

            $hasOwnerMembership = Membership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $organization->owner_user_id)
                ->where('role', OrganizationRole::Owner->value)
                ->exists();

            if (! $hasOwnerMembership) {
                throw new LogicException('Organization ownership must be transferred to an existing owner member.');
            }
        });

        static::creating(function (Organization $organization) {
            if (empty($organization->slug)) {
                $organization->slug = static::generateUniqueOrganizationSlug($organization->name);
            }
        });

        static::updating(function (Organization $organization) {
            if ($organization->isDirty('name')) {
                $organization->slug = static::generateUniqueOrganizationSlug($organization->name, $organization->id);
            }
        });
    }

    /**
     * Get the organization owner.
     */
    public function owner(): ?User
    {
        return $this->belongsTo(User::class, 'owner_user_id')->first();
    }

    /**
     * Get all members of this organization.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members', 'organization_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this organization.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all normalized roles for this organization.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Get all invitations for this organization.
     *
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduling_granularity' => 'integer',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
