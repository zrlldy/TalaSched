<?php

namespace App\Models;

use Database\Factories\AcademicUnitTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'code', 'name', 'is_system', 'display_order'])]
class AcademicUnitType extends Model
{
    /** @use HasFactory<AcademicUnitTypeFactory> */
    use HasFactory;

    public function units(): HasMany
    {
        return $this->hasMany(AcademicUnit::class);
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
