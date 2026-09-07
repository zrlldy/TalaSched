<?php

namespace App\Http\Controllers\Scheduling;

use App\Enums\AcademicYearStatus;
use App\Enums\CapabilityKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreTimetableRequest;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\Timetable;
use App\Scheduling\CreateTimetable;
use App\Scheduling\TimetableDirectoryQuery;
use App\Subscriptions\CapabilityGuard;
use App\Subscriptions\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TimetableController extends Controller
{
    public function index(Request $request, Organization $currentOrganization, TimetableDirectoryQuery $query, CapabilityGuard $capabilities, UsageService $usage): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', [Timetable::class, $currentOrganization]);
        $search = mb_substr(trim($request->string('search')->toString()), 0, 120);
        $canCreate = $request->user()->can('create', [Timetable::class, $currentOrganization]);
        $limit = $capabilities->limit($currentOrganization, CapabilityKey::MaxActiveTimetables);
        $current = $limit === null ? null : $usage->current($currentOrganization, CapabilityKey::MaxActiveTimetables);

        return Inertia::render('scheduling/Index', [
            'timetables' => $query->query($currentOrganization, $search)->paginate(20)->withQueryString()->through($query->summary(...)),
            'search' => $search,
            'canCreateTimetable' => $canCreate,
            'capacity' => ['current' => $current, 'limit' => $limit, 'available' => $limit !== null && $current < $limit],
            'periods' => $canCreate ? AcademicPeriod::query()
                ->where('organization_id', $currentOrganization->getKey())
                ->whereHas('academicYear', fn ($query) => $query->where('status', '!=', AcademicYearStatus::Closed))
                ->whereDoesntHave('timetables')
                ->with('academicYear')
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicPeriod $period): array => ['id' => $period->public_id, 'name' => $period->name, 'year' => $period->academicYear->name])
                ->all() : [],
        ]);
    }

    public function store(StoreTimetableRequest $request, Organization $currentOrganization, CreateTimetable $create): RedirectResponse
    {
        $timetable = $create->handle($currentOrganization, $request->user(), $request->validated('academic_period_id'), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Timetable created. Your first draft is ready.')]);

        return to_route('scheduling.timetables.show', ['current_organization' => $currentOrganization->slug, 'timetable' => $timetable->public_id]);
    }
}
