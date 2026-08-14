<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\ResourceType;
use Database\Factories\SchedulingResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'type', 'name', 'is_active'])]
class SchedulingResource extends Model
{
    /** @use HasFactory<SchedulingResourceFactory> */
    use HasFactory, HasPublicId;

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(ResourceAvailabilityRule::class);
    }

    protected function casts(): array
    {
        return ['type' => ResourceType::class, 'is_active' => 'boolean'];
    }
}
