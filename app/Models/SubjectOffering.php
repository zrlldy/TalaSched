<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\SubjectOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'academic_period_id', 'subject_id', 'student_group_id', 'owning_academic_unit_id', 'code', 'expected_enrollment', 'status'])]
class SubjectOffering extends Model
{
    /** @use HasFactory<SubjectOfferingFactory> */
    use HasFactory, HasPublicId;

    public function studentGroup(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(OfferingComponent::class);
    }
}
