<?php

namespace Database\Seeders;

use App\Enums\CapabilityKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class SubscriptionCatalogSeeder extends Seeder
{
    /**
     * Seed capability metadata and built-in plan values.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $timestamp = now();
            $plans = [
                'starter' => 'Starter',
                'professional' => 'Professional',
                'enterprise' => 'Enterprise',
            ];

            DB::table('plans')->upsert(
                collect($plans)
                    ->map(fn (string $name, string $code): array => [
                        'code' => $code,
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->values()
                    ->all(),
                ['code'],
                ['name', 'is_active', 'updated_at'],
            );

            DB::table('capabilities')->upsert(
                collect(CapabilityKey::cases())
                    ->map(fn (CapabilityKey $capability): array => [
                        'code' => $capability->value,
                        'name' => $capability->label(),
                        'value_type' => $capability->valueType(),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all(),
                ['code'],
                ['name', 'value_type', 'updated_at'],
            );

            $planRecords = DB::table('plans')
                ->whereIn('code', array_keys($plans))
                ->get()
                ->keyBy('code');
            $capabilityRecords = DB::table('capabilities')
                ->whereIn('code', array_map(fn (CapabilityKey $capability): string => $capability->value, CapabilityKey::cases()))
                ->get()
                ->keyBy('code');

            $values = [
                'starter' => [
                    CapabilityKey::ManualScheduling->value => true,
                    CapabilityKey::AutomaticScheduling->value => false,
                    CapabilityKey::CustomSchedulingRules->value => false,
                    CapabilityKey::CustomExcelTemplates->value => false,
                    CapabilityKey::ApprovalWorkflows->value => false,
                    CapabilityKey::TimetableVersioning->value => true,
                    CapabilityKey::FacultyWorkloadReports->value => false,
                    CapabilityKey::CustomRoles->value => false,
                    CapabilityKey::MultiCampus->value => false,
                    CapabilityKey::ApiAccess->value => false,
                    CapabilityKey::MaxMembers->value => 25,
                    CapabilityKey::MaxActiveTimetables->value => 1,
                ],
                'professional' => [
                    CapabilityKey::ManualScheduling->value => true,
                    CapabilityKey::AutomaticScheduling->value => true,
                    CapabilityKey::CustomSchedulingRules->value => true,
                    CapabilityKey::CustomExcelTemplates->value => true,
                    CapabilityKey::ApprovalWorkflows->value => true,
                    CapabilityKey::TimetableVersioning->value => true,
                    CapabilityKey::FacultyWorkloadReports->value => true,
                    CapabilityKey::CustomRoles->value => true,
                    CapabilityKey::MultiCampus->value => false,
                    CapabilityKey::ApiAccess->value => false,
                    CapabilityKey::MaxMembers->value => 100,
                    CapabilityKey::MaxActiveTimetables->value => 5,
                ],
                'enterprise' => [
                    CapabilityKey::ManualScheduling->value => true,
                    CapabilityKey::AutomaticScheduling->value => true,
                    CapabilityKey::CustomSchedulingRules->value => true,
                    CapabilityKey::CustomExcelTemplates->value => true,
                    CapabilityKey::ApprovalWorkflows->value => true,
                    CapabilityKey::TimetableVersioning->value => true,
                    CapabilityKey::FacultyWorkloadReports->value => true,
                    CapabilityKey::CustomRoles->value => true,
                    CapabilityKey::MultiCampus->value => true,
                    CapabilityKey::ApiAccess->value => true,
                    CapabilityKey::MaxMembers->value => 1000,
                    CapabilityKey::MaxActiveTimetables->value => 50,
                ],
            ];

            $planValues = [];

            foreach ($values as $planCode => $capabilities) {
                $plan = $planRecords->get($planCode);

                if ($plan === null) {
                    throw new LogicException("Subscription plan [{$planCode}] was not seeded.");
                }

                foreach ($capabilities as $capabilityCode => $value) {
                    $capability = $capabilityRecords->get($capabilityCode);

                    if ($capability === null) {
                        throw new LogicException("Capability [{$capabilityCode}] was not seeded.");
                    }

                    $planValues[] = [
                        'plan_id' => $plan->id,
                        'capability_id' => $capability->id,
                        'boolean_value' => is_bool($value) ? $value : null,
                        'integer_value' => is_int($value) ? $value : null,
                    ];
                }
            }

            DB::table('plan_capability_values')->upsert(
                $planValues,
                ['plan_id', 'capability_id'],
                ['boolean_value', 'integer_value'],
            );
        });
    }
}
