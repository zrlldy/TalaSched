<?php

namespace Database\Seeders;

use App\Models\ConstraintDefinition;
use App\Scheduling\ConstraintRegistry;
use Illuminate\Database\Seeder;

class CanonicalConstraintDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        ConstraintDefinition::query()->upsert(
            array_map(fn (array $definition): array => [
                ...$definition,
                'created_at' => $now,
                'updated_at' => $now,
            ], ConstraintRegistry::definitions()),
            ['code'],
            [
                'name',
                'handler',
                'default_severity',
                'configuration_schema_version',
                'is_mandatory',
                'updated_at',
            ],
        );
    }
}
