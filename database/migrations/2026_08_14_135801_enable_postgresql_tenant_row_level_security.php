<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tenantTables = [
        'roles', 'membership_role_assignments', 'academic_years', 'academic_periods',
        'academic_unit_types', 'academic_unit_type_edges', 'academic_units', 'academic_unit_closure',
        'academic_calendars', 'calendar_exceptions', 'scheduling_resources', 'student_groups',
        'faculty_profiles', 'faculty_unit_assignments', 'room_types', 'buildings', 'rooms', 'features',
        'resource_availability_rules', 'subjects', 'subject_components', 'subject_offerings',
        'offering_components', 'offering_instructors', 'timetables', 'timetable_versions',
        'constraint_configurations', 'schedule_entries', 'schedule_entry_resources', 'schedule_reservations',
        'schedule_entry_exceptions', 'schedule_exception_resources', 'approval_workflows',
        'approval_workflow_versions', 'approval_workflow_steps', 'signatory_profiles', 'approval_instances',
        'approval_instance_steps', 'approval_actions', 'organization_subscriptions',
        'organization_entitlement_overrides', 'usage_counters', 'file_assets', 'excel_templates',
        'excel_template_versions', 'export_runs', 'audit_events',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint) WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse($this->tenantTables) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
