<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\TimetableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $academic_year_id
 * @property int $academic_period_id
 * @property int $scheduling_granularity
 */
#[Fillable(['organization_id', 'academic_year_id', 'academic_period_id', 'name', 'timezone', 'scheduling_granularity'])]
class Timetable extends Model
{
    /** @use HasFactory<TimetableFactory> */
    use HasFactory, HasPublicId;

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /** @return HasMany<TimetableVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TimetableVersion::class);
    }
}
