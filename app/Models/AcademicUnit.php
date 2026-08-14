<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\AcademicUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'academic_unit_type_id', 'parent_id', 'code', 'name', 'active_from', 'active_until'])]
class AcademicUnit extends Model
{
    /** @use HasFactory<AcademicUnitFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    public function type(): BelongsTo
    {
        return $this->belongsTo(AcademicUnitType::class, 'academic_unit_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return ['active_from' => 'date', 'active_until' => 'date'];
    }
}
