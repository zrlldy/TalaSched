<?php

namespace App\Scheduling;

use App\Models\AcademicCalendar;
use App\Models\AcademicPeriod;
use App\Models\CalendarException;
use App\Models\FacultyProfile;
use App\Models\OfferingComponent;
use App\Models\Organization;
use App\Models\Room;
use App\Models\ScheduleEntry;
use App\Models\ScheduleReservation;
use App\Models\SchedulingResource;
use App\Models\TimetableVersion;
use Illuminate\Database\Eloquent\Collection;

final readonly class SchedulingContext
{
    /**
     * @param  Collection<int, SchedulingResource>  $resources
     * @param  Collection<int, ScheduleReservation>  $reservations
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, FacultyProfile>  $facultyProfiles
     * @param  Collection<int, AcademicCalendar>  $calendars
     * @param  Collection<int, CalendarException>  $calendarExceptions
     * @param  Collection<int, ScheduleEntry>  $existingEntries
     */
    public function __construct(
        public Organization $organization,
        public TimetableVersion $version,
        public OfferingComponent $offeringComponent,
        public AcademicPeriod $academicPeriod,
        public Collection $resources,
        public Collection $reservations,
        public Collection $rooms,
        public Collection $facultyProfiles,
        public Collection $calendars,
        public Collection $calendarExceptions,
        public Collection $existingEntries,
        public ScheduleEntryData $data,
        public bool $checkVersionEditability = true,
    ) {}

    public function resource(int $resourceId): ?SchedulingResource
    {
        $resource = $this->resources->get($resourceId);

        return $resource instanceof SchedulingResource ? $resource : null;
    }
}
