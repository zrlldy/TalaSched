<?php

namespace App\Models;

use App\Concerns\HasImmutableOrganization;
use App\Enums\ConstraintSeverity;
use Database\Factories\ConstraintConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property ConstraintSeverity $severity */
#[Fillable(['organization_id', 'constraint_definition_id', 'academic_unit_id', 'academic_period_id', 'is_enabled', 'severity', 'priority', 'weight', 'schema_version', 'configuration'])]
class ConstraintConfiguration extends Model
{
    /** @use HasFactory<ConstraintConfigurationFactory> */
    use HasFactory, HasImmutableOrganization;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ConstraintDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConstraintDefinition::class, 'constraint_definition_id');
    }

    /** @return BelongsTo<AcademicUnit, $this> */
    public function academicUnit(): BelongsTo
    {
        return $this->belongsTo(AcademicUnit::class);
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'severity' => ConstraintSeverity::class,
            'priority' => 'integer',
            'weight' => 'decimal:3',
            'schema_version' => 'integer',
            'configuration' => 'array',
        ];
    }
}
