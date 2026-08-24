<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\DeliveryMode;
use App\Enums\SubjectComponentKind;
use Database\Factories\OfferingComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * @property SubjectComponentKind $kind
 * @property DeliveryMode $delivery_mode
 */
#[Fillable(['organization_id', 'subject_offering_id', 'subject_component_id', 'kind', 'name', 'weekly_minutes', 'sessions_per_week', 'duration_minutes', 'minimum_room_capacity', 'required_room_type_id', 'delivery_mode'])]
class OfferingComponent extends Model
{
    /** @use HasFactory<OfferingComponentFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId;

    protected static function booted(): void
    {
        static::saving(function (OfferingComponent $component): void {
            $offeringSubjectId = SubjectOffering::query()
                ->whereKey($component->subject_offering_id)
                ->value('subject_id');
            $subjectComponentSubjectId = $component->subject_component_id === null
                ? null
                : SubjectComponent::query()
                    ->whereKey($component->subject_component_id)
                    ->value('subject_id');

            if ($offeringSubjectId !== null
                && $subjectComponentSubjectId !== null
                && $offeringSubjectId !== $subjectComponentSubjectId) {
                throw new LogicException('An offering component snapshot must belong to the offering subject.');
            }
        });

        static::saving(function (OfferingComponent $component): void {
            if ($component->weekly_minutes < 1 || $component->sessions_per_week < 1 || $component->duration_minutes < 1) {
                throw new LogicException('Offering component scheduling values must be positive.');
            }

            $relatedOrganizationIds = [
                SubjectOffering::query()->whereKey($component->subject_offering_id)->value('organization_id'),
                $component->subject_component_id === null
                    ? null
                    : SubjectComponent::query()->whereKey($component->subject_component_id)->value('organization_id'),
                $component->required_room_type_id === null
                    ? null
                    : RoomType::query()->whereKey($component->required_room_type_id)->value('organization_id'),
            ];

            foreach ($relatedOrganizationIds as $relatedOrganizationId) {
                if ($relatedOrganizationId !== null && $relatedOrganizationId !== $component->organization_id) {
                    throw new LogicException('An offering component and its offering, subject component, and room type must belong to the same organization.');
                }
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }

    /** @return BelongsTo<SubjectComponent, $this> */
    public function subjectComponent(): BelongsTo
    {
        return $this->belongsTo(SubjectComponent::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function requiredRoomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'required_room_type_id');
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'offering_component_features')
            ->withPivot(['organization_id', 'minimum_quantity']);
    }

    /** @return BelongsToMany<FacultyProfile, $this> */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(FacultyProfile::class, 'offering_instructors')
            ->withPivot(['organization_id', 'load_percentage', 'is_primary']);
    }

    protected function casts(): array
    {
        return [
            'kind' => SubjectComponentKind::class,
            'weekly_minutes' => 'integer',
            'sessions_per_week' => 'integer',
            'duration_minutes' => 'integer',
            'minimum_room_capacity' => 'integer',
            'delivery_mode' => DeliveryMode::class,
        ];
    }
}
