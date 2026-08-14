<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\AcademicPeriodKind;
use Database\Factories\AcademicPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'academic_year_id', 'name', 'kind', 'sequence', 'starts_on', 'ends_on'])]
class AcademicPeriod extends Model
{
    /** @use HasFactory<AcademicPeriodFactory> */
    use HasFactory, HasPublicId;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    protected function casts(): array
    {
        return ['kind' => AcademicPeriodKind::class, 'starts_on' => 'date', 'ends_on' => 'date'];
    }
}
