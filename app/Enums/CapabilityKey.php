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
}
