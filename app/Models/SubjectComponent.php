<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use Database\Factories\SubjectComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property SubjectComponentKind $kind
 * @property DeliveryMode $delivery_mode
 */
#[Fillable(['organization_id', 'subject_id', 'kind', 'name', 'weekly_minutes', 'sessions_per_week', 'default_duration_minutes', 'minimum_room_capacity', 'delivery_mode'])]
class SubjectComponent extends Model
{
    /** @use HasFactory<SubjectComponentFactory> */
    use HasFactory, HasImmutableOrganization;

    protected static function booted(): void
    {
        static::saving(function (SubjectComponent $component): void {
            if ($component->weekly_minutes < 1 || $component->sessions_per_week < 1 || $component->default_duration_minutes < 1) {
                throw new LogicException('Subject component scheduling values must be positive.');
            }

            $subjectOrganizationId = Subject::query()->whereKey($component->subject_id)->value('organization_id');

            if ($subjectOrganizationId !== null && $subjectOrganizationId !== $component->organization_id) {
                throw new LogicException('A subject component and its subject must belong to the same organization.');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsToMany<RoomType, $this> */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'subject_component_room_types')
            ->withPivot('organization_id');
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'subject_component_features')
            ->withPivot(['organization_id', 'minimum_quantity']);
    }

    /** @return HasMany<OfferingComponent, $this> */
    public function offeringComponents(): HasMany
    {
        return $this->hasMany(OfferingComponent::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => SubjectComponentKind::class,
            'weekly_minutes' => 'integer',
            'sessions_per_week' => 'integer',
            'default_duration_minutes' => 'integer',
            'minimum_room_capacity' => 'integer',
            'delivery_mode' => DeliveryMode::class,
        ];
    }
}
