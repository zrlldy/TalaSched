<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\AcademicYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'starts_on', 'ends_on', 'status'])]
class AcademicYear extends Model
{
    /** @use HasFactory<AcademicYearFactory> */
    use HasFactory, HasPublicId;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class);
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
