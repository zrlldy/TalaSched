<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\OfferingComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'subject_offering_id', 'subject_component_id', 'kind', 'name', 'weekly_minutes', 'sessions_per_week', 'duration_minutes', 'minimum_room_capacity', 'required_room_type_id', 'delivery_mode'])]
class OfferingComponent extends Model
{
    /** @use HasFactory<OfferingComponentFactory> */
    use HasFactory, HasPublicId;

    public function offering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }
}
