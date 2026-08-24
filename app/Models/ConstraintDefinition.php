<?php

namespace App\Models;

use Database\Factories\ConstraintDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'handler', 'default_severity', 'configuration_schema_version', 'is_mandatory'])]
class ConstraintDefinition extends Model
{
    /** @use HasFactory<ConstraintDefinitionFactory> */
    use HasFactory;

    /** @return HasMany<ConstraintConfiguration, $this> */
    public function configurations(): HasMany
    {
        return $this->hasMany(ConstraintConfiguration::class, 'constraint_definition_id');
    }

    protected function casts(): array
    {
        return [
            'configuration_schema_version' => 'integer',
            'is_mandatory' => 'boolean',
        ];
    }
}
