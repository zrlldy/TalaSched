<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\StudentGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'scheduling_resource_id', 'academic_year_id', 'academic_unit_id', 'code', 'name', 'expected_headcount'])]
class StudentGroup extends Model
{
    /** @use HasFactory<StudentGroupFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public function resource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class, 'scheduling_resource_id');
    }
}
