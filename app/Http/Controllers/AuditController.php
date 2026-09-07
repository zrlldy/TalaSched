<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Organization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class AuditController extends Controller
{
    private const int PerPage = 30;

    public function index(Request $request, Organization $currentOrganization): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', [AuditEvent::class, $currentOrganization]);

        $action = $this->actionFilter($request);
        $events = $this->events($currentOrganization, $action)
            ->cursorPaginate(perPage: self::PerPage, cursor: $this->cursor($request));

        return Inertia::render('audits/Index', [
            'actions' => $this->actions($currentOrganization),
            'entries' => array_map(
                fn (stdClass $event): array => $this->entry($event),
                $events->items(),
            ),
            'filters' => ['action' => $action],
            'nextCursor' => $events->nextCursor() === null
                ? null
                : Crypt::encryptString($events->nextCursor()->encode()),
        ]);
    }

    private function actionFilter(Request $request): ?string
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
        ]);
        $action = $validated['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    private function cursor(Request $request): ?Cursor
    {
        $encryptedCursor = $request->query('cursor');

        if (! is_string($encryptedCursor) || $encryptedCursor === '') {
            return null;
        }

        try {
            $cursor = Cursor::fromEncoded(Crypt::decryptString($encryptedCursor));
        } catch (DecryptException) {
            abort(404);
        }

        abort_unless($cursor instanceof Cursor, 404);

        return $cursor;
    }

    private function events(Organization $organization, ?string $action): Builder
    {
        $events = DB::table('audit_events as events')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_user_id')
            ->where('events.organization_id', $organization->getKey())
            ->orderByDesc('events.occurred_at')
            ->orderByDesc('events.id')
            ->select([
                'events.id',
                'events.action',
                'events.subject_type',
                'events.before',
                'events.after',
                'events.occurred_at',
                'events.actor_user_id',
                'actors.name as actor_name',
            ]);

        if ($action !== null) {
            $events->where('events.action', $action);
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function actions(Organization $organization): array
    {
        $actions = DB::table('audit_events')
            ->where('organization_id', $organization->getKey())
            ->distinct()
            ->orderBy('action')
            ->limit(100)
            ->pluck('action')
            ->map(static fn (mixed $action): string => (string) $action)
            ->values()
            ->all();

        return array_values($actions);
    }

    /**
     * @return array{action: string, actor_name: string, subject_label: string|null, change_fields: list<string>, occurred_at: string}
     */
    private function entry(stdClass $event): array
    {
        return [
            'action' => (string) $event->action,
            'actor_name' => $event->actor_user_id === null
                ? 'System'
                : (is_string($event->actor_name) && $event->actor_name !== '' ? $event->actor_name : 'Former account'),
            'subject_label' => $this->subjectLabel($event->subject_type),
            'change_fields' => $this->changeFields($event->before, $event->after),
            'occurred_at' => Carbon::parse((string) $event->occurred_at)->toISOString(),
        ];
    }

    private function subjectLabel(mixed $subjectType): ?string
    {
        if (! is_string($subjectType) || $subjectType === '') {
            return null;
        }

        return Str::headline(Str::afterLast($subjectType, '\\'));
    }

    /**
     * Return only field names so the audit ledger never exposes snapshot values.
     *
     * @return list<string>
     */
    private function changeFields(mixed $before, mixed $after): array
    {
        $fields = [];

        foreach ([$before, $after] as $snapshot) {
            if (! is_string($snapshot) || $snapshot === '') {
                continue;
            }

            $values = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($values)) {
                continue;
            }

            foreach (array_keys($values) as $field) {
                if (is_string($field)) {
                    $fields[$field] = true;
                }
            }
        }

        return array_keys($fields);
    }
}
