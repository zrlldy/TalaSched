<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Concerns\HasPublicId;
use App\Enums\ExcelTemplateStatus;
use Database\Factories\ExcelTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $public_id
 * @property int $organization_id
 * @property string $name
 * @property ExcelTemplateStatus $status
 * @property-read Organization $organization
 * @property-read Collection<int, ExcelTemplateVersion> $versions
 */
#[Fillable(['organization_id', 'name', 'status'])]
class ExcelTemplate extends Model
{
    /** @use HasFactory<ExcelTemplateFactory> */
    use HasFactory, HasImmutableOrganization, HasPublicId, SoftDeletes;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ExcelTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ExcelTemplateVersion::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ExcelTemplateStatus::class,
        ];
    }
}
