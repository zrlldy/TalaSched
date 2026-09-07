<?php

namespace App\Actions;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\FacultyProfile;
use App\Models\Organization;
use App\Models\Room;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Timetable;
use App\Scheduling\TimetableDirectoryQuery;

class GetWorkspaceOverview
{
    public function __construct(private TimetableDirectoryQuery $timetables) {}

    /** @return array<string, mixed> */
    public function handle(Organization $organization): array
    {
        $counts = [];

        foreach ([
            'years' => AcademicYear::class, 'periods' => AcademicPeriod::class,
            'faculty' => FacultyProfile::class, 'rooms' => Room::class,
            'subjects' => Subject::class, 'offerings' => SubjectOffering::class,
            'groups' => StudentGroup::class, 'timetables' => Timetable::class,
        ] as $key => $model) {
            $counts[$key] = $model::query()->where('organization_id', $organization->getKey())->count();
        }

        return [
            'counts' => $counts,
            'recentTimetables' => $this->timetables->query($organization)->limit(5)->get()->map($this->timetables->summary(...))->all(),
        ];
    }
}
