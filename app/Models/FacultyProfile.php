<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\FacultyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'scheduling_resource_id', 'user_id', 'employee_number', 'position', 'employment_type', 'maximum_daily_minutes', 'maximum_weekly_minutes'])]
class FacultyProfile extends Model
{
    /** @use HasFactory<FacultyProfileFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }
}
