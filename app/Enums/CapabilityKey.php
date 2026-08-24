<?php

namespace App\Enums;

enum CapabilityKey: string
{
    case ManualScheduling = 'manual_scheduling';
    case AutomaticScheduling = 'automatic_scheduling';
    case CustomSchedulingRules = 'custom_scheduling_rules';
    case CustomExcelTemplates = 'custom_excel_templates';
    case ApprovalWorkflows = 'approval_workflows';
    case TimetableVersioning = 'timetable_versioning';
    case FacultyWorkloadReports = 'faculty_workload_reports';
    case CustomRoles = 'custom_roles';
    case MultiCampus = 'multi_campus';
    case ApiAccess = 'api_access';
    case MaxMembers = 'max_members';
    case MaxActiveTimetables = 'max_active_timetables';

    /**
     * Get the stable display name for the capability.
     */
    public function label(): string
    {
        return match ($this) {
            self::ManualScheduling => 'Manual scheduling',
            self::AutomaticScheduling => 'Automatic scheduling',
            self::CustomSchedulingRules => 'Custom scheduling rules',
            self::CustomExcelTemplates => 'Custom Excel templates',
            self::ApprovalWorkflows => 'Approval workflows',
            self::TimetableVersioning => 'Timetable versioning',
            self::FacultyWorkloadReports => 'Faculty workload reports',
            self::CustomRoles => 'Custom roles',
            self::MultiCampus => 'Multi-campus organizations',
            self::ApiAccess => 'API access',
            self::MaxMembers => 'Maximum members',
            self::MaxActiveTimetables => 'Maximum active timetables',
        };
    }

    /**
     * Get the storage type used by the capability catalog.
     */
    public function valueType(): string
    {
        return match ($this) {
            self::MaxMembers, self::MaxActiveTimetables => 'integer',
            default => 'boolean',
        };
    }
}
