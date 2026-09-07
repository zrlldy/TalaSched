<?php

namespace App\Scheduling;

use App\Models\Organization;
use App\Models\Timetable;
use Illuminate\Database\Eloquent\Builder;

class TimetableDirectoryQuery
{
    /** @return Builder<Timetable> */
    public function query(Organization $organization, string $search = ''): Builder
    {
        return Timetable::query()
            ->where('organization_id', $organization->getKey())
            ->when($search !== '', fn (Builder $query) => $query->whereLike('name', '%'.$search.'%'))
            ->with([
                'academicPeriod.academicYear',
                'versions' => fn ($query) => $query->withCount('entries')->orderByDesc('version_number')->limit(1),
            ])
            ->orderByDesc('id');
    }

    /** @return array{id: string, name: string, period: string, year: string, version: array{id: string, number: int, status: string, entries: int}|null} */
    public function summary(Timetable $timetable): array
    {
        $version = $timetable->versions->first();

        return [
            'id' => $timetable->public_id,
            'name' => $timetable->name,
            'period' => $timetable->academicPeriod->name,
            'year' => $timetable->academicPeriod->academicYear->name,
            'version' => $version === null ? null : [
                'id' => $version->public_id,
                'number' => (int) $version->version_number,
                'status' => $version->status->value,
                'entries' => (int) $version->entries_count,
            ],
        ];
    }
}
