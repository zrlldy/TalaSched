<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'ancestor_id', 'descendant_id', 'depth'])]
class AcademicUnitClosure extends Model
{
    use HasImmutableOrganization;

    protected $table = 'academic_unit_closure';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the organization that owns the closure row.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the ancestor unit represented by this closure row.
     *
     * @return BelongsTo<AcademicUnit, $this>
     */
    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'ancestor_id');
    }

    /**
     * Get the descendant unit represented by this closure row.
     *
     * @return BelongsTo<AcademicUnit, $this>
     */
    public function descendant(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class, 'descendant_id');
    }

    protected function casts(): array
    {
        return ['depth' => 'integer'];
    }
}
