<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\TimetableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'academic_year_id', 'academic_period_id', 'name', 'timezone', 'scheduling_granularity'])]
class Timetable extends Model
{
    /** @use HasFactory<TimetableFactory> */
    use HasFactory, HasPublicId;

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TimetableVersion::class);
    }
}
