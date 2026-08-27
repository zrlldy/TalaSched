<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $tenantOwnedPivots = [
        'role_permissions' => ['roles', 'role_id'],
        'student_group_periods' => ['student_groups', 'student_group_id'],
        'room_features' => ['rooms', 'room_id'],
        'subject_component_room_types' => ['subject_components', 'subject_component_id'],
        'subject_component_features' => ['subject_components', 'subject_component_id'],
        'offering_component_features' => ['offering_components', 'offering_component_id'],
        'approval_step_roles' => ['approval_workflow_steps', 'approval_workflow_step_id'],
    ];

    /** @var array<string, string> */
    private array $tenantParentKeys = [
        'organization_members' => 'organization_members_tenant_key',
        'room_types' => 'room_types_tenant_key',
        'buildings' => 'buildings_tenant_key',
        'features' => 'features_tenant_key',
        'subject_components' => 'subject_components_tenant_key',
        'schedule_entry_exceptions' => 'schedule_entry_exceptions_tenant_key',
        'approval_workflows' => 'approval_workflows_tenant_key',
        'approval_workflow_versions' => 'approval_workflow_versions_tenant_key',
        'approval_workflow_steps' => 'approval_workflow_steps_tenant_key',
        'approval_instances' => 'approval_instances_tenant_key',
        'approval_instance_steps' => 'approval_instance_steps_tenant_key',
        'file_assets' => 'file_assets_tenant_key',
        'excel_templates' => 'excel_templates_tenant_key',
        'excel_template_versions' => 'excel_template_versions_tenant_key',
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private array $tenantRelationships = [
        ['role_permissions', 'role_id', 'roles', 'role_permission_role_tenant_fk'],
        ['membership_role_assignments', 'membership_id', 'organization_members', 'membership_assignment_member_tenant_fk'],
        ['membership_role_assignments', 'role_id', 'roles', 'membership_assignment_role_tenant_fk'],
        ['academic_periods', 'academic_year_id', 'academic_years', 'academic_period_year_tenant_fk'],
        ['academic_unit_type_edges', 'parent_type_id', 'academic_unit_types', 'unit_type_edge_parent_tenant_fk'],
        ['academic_unit_type_edges', 'child_type_id', 'academic_unit_types', 'unit_type_edge_child_tenant_fk'],
        ['academic_units', 'academic_unit_type_id', 'academic_unit_types', 'academic_unit_type_tenant_fk'],
        ['academic_units', 'parent_id', 'academic_units', 'academic_unit_parent_tenant_fk'],
        ['academic_unit_closure', 'ancestor_id', 'academic_units', 'unit_closure_ancestor_tenant_fk'],
        ['academic_unit_closure', 'descendant_id', 'academic_units', 'unit_closure_descendant_tenant_fk'],
        ['academic_calendars', 'academic_period_id', 'academic_periods', 'academic_calendar_period_tenant_fk'],
        ['calendar_exceptions', 'academic_period_id', 'academic_periods', 'calendar_exception_period_tenant_fk'],
        ['student_groups', 'scheduling_resource_id', 'scheduling_resources', 'student_group_resource_tenant_fk'],
        ['student_groups', 'academic_year_id', 'academic_years', 'student_group_year_tenant_fk'],
        ['student_groups', 'academic_unit_id', 'academic_units', 'student_group_unit_tenant_fk'],
        ['student_group_periods', 'student_group_id', 'student_groups', 'student_group_period_group_tenant_fk'],
        ['student_group_periods', 'academic_period_id', 'academic_periods', 'student_group_period_period_tenant_fk'],
        ['faculty_profiles', 'scheduling_resource_id', 'scheduling_resources', 'faculty_profile_resource_tenant_fk'],
        ['faculty_unit_assignments', 'faculty_profile_id', 'faculty_profiles', 'faculty_unit_faculty_tenant_fk'],
        ['faculty_unit_assignments', 'academic_unit_id', 'academic_units', 'faculty_unit_unit_tenant_fk'],
        ['buildings', 'campus_academic_unit_id', 'academic_units', 'building_campus_unit_tenant_fk'],
        ['rooms', 'scheduling_resource_id', 'scheduling_resources', 'room_resource_tenant_fk'],
        ['rooms', 'building_id', 'buildings', 'room_building_tenant_fk'],
        ['rooms', 'room_type_id', 'room_types', 'room_type_tenant_fk'],
        ['room_features', 'room_id', 'rooms', 'room_feature_room_tenant_fk'],
        ['room_features', 'feature_id', 'features', 'room_feature_feature_tenant_fk'],
        ['resource_availability_rules', 'scheduling_resource_id', 'scheduling_resources', 'availability_resource_tenant_fk'],
        ['resource_availability_rules', 'academic_period_id', 'academic_periods', 'availability_period_tenant_fk'],
        ['subject_components', 'subject_id', 'subjects', 'subject_component_subject_tenant_fk'],
        ['subject_component_room_types', 'subject_component_id', 'subject_components', 'component_room_type_component_tenant_fk'],
        ['subject_component_room_types', 'room_type_id', 'room_types', 'component_room_type_type_tenant_fk'],
        ['subject_component_features', 'subject_component_id', 'subject_components', 'component_feature_component_tenant_fk'],
        ['subject_component_features', 'feature_id', 'features', 'component_feature_feature_tenant_fk'],
        ['subject_offerings', 'academic_period_id', 'academic_periods', 'subject_offering_period_tenant_fk'],
        ['subject_offerings', 'subject_id', 'subjects', 'subject_offering_subject_tenant_fk'],
        ['subject_offerings', 'student_group_id', 'student_groups', 'subject_offering_group_tenant_fk'],
        ['subject_offerings', 'owning_academic_unit_id', 'academic_units', 'subject_offering_unit_tenant_fk'],
        ['offering_components', 'subject_offering_id', 'subject_offerings', 'offering_component_offering_tenant_fk'],
        ['offering_components', 'subject_component_id', 'subject_components', 'offering_component_subject_component_tenant_fk'],
        ['offering_components', 'required_room_type_id', 'room_types', 'offering_component_room_type_tenant_fk'],
        ['offering_component_features', 'offering_component_id', 'offering_components', 'offering_feature_component_tenant_fk'],
        ['offering_component_features', 'feature_id', 'features', 'offering_feature_feature_tenant_fk'],
        ['offering_instructors', 'offering_component_id', 'offering_components', 'offering_instructor_component_tenant_fk'],
        ['offering_instructors', 'faculty_profile_id', 'faculty_profiles', 'offering_instructor_faculty_tenant_fk'],
        ['timetables', 'academic_year_id', 'academic_years', 'timetable_year_tenant_fk'],
        ['timetables', 'academic_period_id', 'academic_periods', 'timetable_period_tenant_fk'],
        ['timetable_versions', 'timetable_id', 'timetables', 'timetable_version_timetable_tenant_fk'],
        ['timetable_versions', 'based_on_version_id', 'timetable_versions', 'timetable_version_source_tenant_fk'],
        ['constraint_configurations', 'academic_unit_id', 'academic_units', 'constraint_configuration_unit_tenant_fk'],
        ['constraint_configurations', 'academic_period_id', 'academic_periods', 'constraint_configuration_period_tenant_fk'],
        ['schedule_entries', 'timetable_version_id', 'timetable_versions', 'schedule_entry_version_tenant_fk'],
        ['schedule_entries', 'offering_component_id', 'offering_components', 'schedule_entry_component_tenant_fk'],
        ['schedule_entry_resources', 'schedule_entry_id', 'schedule_entries', 'entry_resource_entry_tenant_fk'],
        ['schedule_entry_resources', 'scheduling_resource_id', 'scheduling_resources', 'entry_resource_resource_tenant_fk'],
        ['schedule_reservations', 'timetable_version_id', 'timetable_versions', 'reservation_version_tenant_fk'],
        ['schedule_reservations', 'schedule_entry_id', 'schedule_entries', 'reservation_entry_tenant_fk'],
        ['schedule_reservations', 'scheduling_resource_id', 'scheduling_resources', 'reservation_resource_tenant_fk'],
        ['schedule_entry_exceptions', 'schedule_entry_id', 'schedule_entries', 'entry_exception_entry_tenant_fk'],
        ['schedule_exception_resources', 'schedule_entry_exception_id', 'schedule_entry_exceptions', 'exception_resource_exception_tenant_fk'],
        ['schedule_exception_resources', 'scheduling_resource_id', 'scheduling_resources', 'exception_resource_resource_tenant_fk'],
        ['approval_workflow_versions', 'approval_workflow_id', 'approval_workflows', 'workflow_version_workflow_tenant_fk'],
        ['approval_workflow_steps', 'approval_workflow_version_id', 'approval_workflow_versions', 'workflow_step_version_tenant_fk'],
        ['approval_step_roles', 'approval_workflow_step_id', 'approval_workflow_steps', 'approval_step_role_step_tenant_fk'],
        ['approval_step_roles', 'role_id', 'roles', 'approval_step_role_role_tenant_fk'],
        ['signatory_profiles', 'academic_unit_id', 'academic_units', 'signatory_profile_unit_tenant_fk'],
        ['approval_instances', 'timetable_version_id', 'timetable_versions', 'approval_instance_version_tenant_fk'],
        ['approval_instances', 'approval_workflow_version_id', 'approval_workflow_versions', 'approval_instance_workflow_tenant_fk'],
        ['approval_instance_steps', 'approval_instance_id', 'approval_instances', 'approval_instance_step_instance_tenant_fk'],
        ['approval_actions', 'approval_instance_step_id', 'approval_instance_steps', 'approval_action_step_tenant_fk'],
        ['excel_template_versions', 'excel_template_id', 'excel_templates', 'excel_template_version_template_tenant_fk'],
        ['excel_template_versions', 'file_asset_id', 'file_assets', 'excel_template_version_file_tenant_fk'],
        ['export_runs', 'timetable_version_id', 'timetable_versions', 'export_run_timetable_version_tenant_fk'],
        ['export_runs', 'excel_template_version_id', 'excel_template_versions', 'export_run_template_version_tenant_fk'],
        ['export_runs', 'artifact_file_asset_id', 'file_assets', 'export_run_artifact_tenant_fk'],
    ];

    public function up(): void
    {
        $this->addAndBackfillPivotOrganizationIds();
        $this->assertExistingRowsAreTenantSafe();
        $this->makePivotOrganizationIdsRequired();
        $this->addTenantParentKeys();
        $this->addTenantForeignKeys();
        $this->enablePivotRowLevelSecurity();
    }

    public function down(): void
    {
        $this->disablePivotRowLevelSecurity();

        foreach (array_reverse($this->tenantRelationships) as [$table, $foreignKey, , $constraint]) {
            $foreign = DB::getDriverName() === 'sqlite'
                ? ['organization_id', $foreignKey]
                : $constraint;

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign));
        }

        foreach (array_reverse($this->tenantParentKeys, true) as $table => $index) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($index));
        }

        foreach (array_reverse($this->tenantOwnedPivots, true) as $table => $_) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['organization_id']);
                $blueprint->dropColumn('organization_id');
            });
        }
    }

    private function addAndBackfillPivotOrganizationIds(): void
    {
        foreach ($this->tenantOwnedPivots as $table => [$sourceTable, $sourceKey]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreignId('organization_id')->nullable());

            DB::table($table)->update([
                'organization_id' => DB::table($sourceTable)
                    ->select('organization_id')
                    ->whereColumn("{$sourceTable}.id", "{$table}.{$sourceKey}"),
            ]);
        }
    }

    private function assertExistingRowsAreTenantSafe(): void
    {
        foreach ($this->tenantOwnedPivots as $table => $_) {
            if (DB::table($table)->whereNull('organization_id')->exists()) {
                throw new RuntimeException("Cannot assign an organization to every row in {$table}.");
            }
        }

        foreach ($this->tenantRelationships as [$table, $foreignKey, $parentTable]) {
            $hasInvalidReference = DB::table("{$table} as tenant_child")
                ->whereNotNull("tenant_child.{$foreignKey}")
                ->leftJoin("{$parentTable} as tenant_parent", function ($join) use ($foreignKey): void {
                    $join->on('tenant_parent.id', '=', "tenant_child.{$foreignKey}")
                        ->on('tenant_parent.organization_id', '=', 'tenant_child.organization_id');
                })
                ->whereNull('tenant_parent.id')
                ->exists();

            if ($hasInvalidReference) {
                throw new RuntimeException("Cross-organization reference found at {$table}.{$foreignKey}.");
            }
        }
    }

    private function makePivotOrganizationIdsRequired(): void
    {
        foreach ($this->tenantOwnedPivots as $table => $_) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('organization_id')->nullable(false)->change();
                $blueprint->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }
    }

    private function addTenantParentKeys(): void
    {
        foreach ($this->tenantParentKeys as $table => $index) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['organization_id', 'id'], $index));
        }
    }

    private function addTenantForeignKeys(): void
    {
        foreach ($this->tenantRelationships as [$table, $foreignKey, $parentTable, $constraint]) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey, $parentTable, $constraint): void {
                $blueprint->foreign(['organization_id', $foreignKey], $constraint)
                    ->references(['organization_id', 'id'])
                    ->on($parentTable)
                    ->noActionOnDelete();
            });
        }
    }

    private function enablePivotRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->tenantOwnedPivots) as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint) WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint)");
        }
    }

    private function disablePivotRowLevelSecurity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(array_keys($this->tenantOwnedPivots)) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
