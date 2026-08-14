# Frontend and UX Architecture

This document defines the target Inertia/Vue product experience consistent with the [system architecture](../architecture/ARCHITECTURE.md) and [backend contracts](../backend/BACKEND_DESIGN.md). It preserves the current Vue starter foundation and does not implement or restyle production pages yet.

## Product frame

- **Concrete subject:** an institutional scheduler assembling a publishable weekly timetable across teachers, student groups, rooms, academic rules, and approvals.
- **Primary audience:** registrars and schedulers working for hours in a dense operational interface; secondary audiences are approvers, faculty, administrators, and read-only viewers.
- **Single core job:** turn prepared academic resources into a conflict-free, approved, published timetable whose consequences are easy to inspect.
- **Design promise:** show scheduling truth clearly—what occupies time, what conflicts, what changed, and what is ready to publish.

This is an operations product, not a marketing dashboard. Density is useful when hierarchy, state, and keyboard access remain legible.

## Existing frontend foundation

- Inertia 3 and Vue 3 page architecture.
- Tailwind CSS 4 semantic variables with light/dark modes.
- Reusable Reka-based UI primitives and Lucide icons.
- Sidebar/header shells, breadcrumbs, flash toasts, dialogs, sheets, forms, authentication/settings pages, organization switching, invitations, and membership administration.
- Wayfinder-generated TypeScript routes.

The current visual language is intentionally neutral and largely inherited from the Laravel starter. Preserve working auth and organization flows while introducing product-specific tokens and navigation incrementally.

## Design direction

### First-pass concept: the scheduling instrument panel

The interface borrows from drafting tables, bell schedules, and registrar worksheets: precise ruled time surfaces, restrained institutional color, and prominent state stamps. The memorable element is a continuous **time rail** that aligns schedule grids, conflict details, and daily workload summaries.

#### Color tokens

| Token | Hex | Use |
|---|---|---|
| Ledger paper | `#F5F7FB` | cool application background |
| Ink navy | `#17243A` | primary text and navigation |
| Schedule blue | `#315EFB` | selected time, primary actions, focus |
| Bell amber | `#E9A72F` | review state and soft warnings |
| Conflict coral | `#D64A5B` | hard conflicts and destructive states |
| Availability mint | `#23866F` | available/published confirmation |

Dark mode maps these semantic roles to accessible darker surfaces; it must not simply invert timetable colors because color carries scheduling meaning.

#### Type roles

- **Interface/body:** keep the installed Instrument Sans stack initially for continuity and dependency-free delivery.
- **Schedule data:** a tabular-numeral system mono stack for time labels, room codes, subject codes, and version identifiers.
- **Display:** use tighter, heavier Instrument Sans with measured letter spacing until a self-hosted display face is explicitly approved.

Do not add a web-font network dependency. A future self-hosted font choice is an unresolved design decision.

#### Shape and spacing

- 4 px base spacing with 8/12/16/24/32 px operational rhythm.
- 6–8 px radii on controls and panels; schedule cells remain nearly square to retain worksheet precision.
- One-pixel rules organize data; shadows are reserved for floating editors, drag previews, and dialogs.
- Minimum interactive target 40 px even when the visible data row is denser.

### Self-critique and revision

The first pass risked becoming a generic admin dashboard with colored status cards. The revision removes decorative KPI panels from the core workspace and spends the distinctive treatment on the time rail, conflict geometry, version-state strip, and approval stamp. Color is semantic rather than decorative, and schedule cells display real subject/group/room content instead of anonymous blocks.

## Information architecture

```text
Organization switcher
├── Home
│   ├── Current period readiness
│   ├── My approvals / conflicts
│   └── Recent timetable versions
├── Academic setup
│   ├── Academic years and periods
│   ├── Structure
│   ├── Calendars
│   └── Student groups
├── Resources
│   ├── Faculty
│   ├── Rooms and features
│   └── Availability
├── Courses
│   ├── Subjects
│   └── Offerings
├── Timetables
│   ├── Workspace
│   ├── Versions
│   └── Published schedules
├── Approvals
│   ├── Inbox
│   ├── Workflows
│   └── Signatories
├── Templates and exports
├── Reports
└── Organization settings
    ├── Members and roles
    ├── Scheduling rules
    ├── Plan and usage
    └── Audit log
```

Navigation items are filtered by server-provided permissions and capabilities, but hidden navigation never replaces server authorization. If a user can view retained data but cannot create more because of a downgrade, keep the read path visible and disable only the restricted mutation with an explanation.

## Application shell

Desktop uses the existing collapsible left sidebar. The organization/period context remains visible above the work surface. Mobile uses a sheet for global navigation and task-specific full-screen panels instead of shrinking a desktop schedule grid beyond usability.

```text
┌──────────────┬────────────────────────────────────────────────────┐
│ Organization │ Period / Version / Status                Actions  │
│──────────────│────────────────────────────────────────────────────│
│ Home         │ Breadcrumbs / contextual filters                   │
│ Setup        │                                                    │
│ Resources    │                  page workspace                    │
│ Courses      │                                                    │
│ Timetables   │                                                    │
│ Approvals    │                                                    │
│ Templates    │                                                    │
│──────────────│────────────────────────────────────────────────────│
│ User / Help  │ save state, async activity, connection feedback   │
└──────────────┴────────────────────────────────────────────────────┘
```

The top context bar answers four questions without opening a menu: which organization, which academic period, which timetable version, and what state is it in?

## Page and component architecture

```text
resources/js/
  pages/{domain}/               Inertia route-level pages
  components/{domain}/         reusable domain interaction components
  components/ui/               low-level existing UI primitives
  layouts/                      global and focused workspace shells
  composables/                  interaction/session concerns
  types/contracts/             backend-owned response and issue types
  routes/ and actions/          Wayfinder generated; never hand-edited
```

- Route pages coordinate props and domain components; they do not reproduce business rules.
- Domain components receive typed data and emit intent such as `save`, `validate`, `move`, or `publish`.
- Server props own permissions, capabilities, persisted data, and initial filters.
- Client state owns transient selections, editor drafts, panel state, drag previews, and uncommitted warning acknowledgements.
- Shareable filters are encoded in the URL. Do not place authoritative timetable state in a client store.
- Prefer existing UI primitives before adding new low-level components.

## Server-to-client contracts

Every organization page receives a compact shared context:

```ts
type OrganizationContext = {
    organization: { id: string; name: string; slug: string; timezone: string };
    membership: { permissions: string[]; scopedUnits: string[] };
    entitlements: Record<string, boolean | number | null>;
    period?: { id: string; name: string; startsOn: string; endsOn: string };
    timetable?: { id: string; versionId: string; versionNumber: number; status: string; lockVersion: number };
    correlationId: string;
};
```

Large props are deferred by domain: hierarchy trees, timetable entries, workload summaries, approval history, and export history. Every deferred region has a skeleton matching its final geometry and a focused retry state.

Use public identifiers at the frontend contract boundary. Dates use ISO 8601 date strings; timestamps include offsets; weekly schedule times use integer local minutes plus preformatted labels. The organization timezone is always visible where dated exceptions or publication timestamps could be misunderstood.

## Core scheduling workspace

### Desktop layout

```text
┌───────────────────────────────────────────────────────────────────┐
│ Period ▾  Version 3 · Draft   Undo   Compare   Submit for review  │
├───────────────┬────────────────────────────────────┬──────────────┤
│ Offerings     │ Mon  Tue  Wed  Thu  Fri            │ Inspector    │
│ Search/filter │ ───── continuous time rail ─────── │ Entry        │
│ Unscheduled 8 │ 08:00 [CS101 · BSIT-1A]            │ conflicts 0  │
│               │       [Room 301 · A. Cruz]          │ warnings 1   │
│ Faculty       │ 09:00                               │ requirements │
│ Rooms         │ ...                                 │ Save changes │
└───────────────┴────────────────────────────────────┴──────────────┘
```

- Left panel: unscheduled offering components and optional resource filters.
- Center: canonical weekly grid with time rail, day columns, current-time/operating-hour context, and keyboard navigation.
- Right inspector: selected entry editor, requirements, assigned resources, hard conflicts, and soft warnings.
- Version-state strip: makes draft/review/approved/published state and allowed actions unambiguous.

The grid derives teacher, group, room, and academic-unit views through filters; it does not create separate schedule datasets.

### Interaction model

- Click or keyboard command opens a create editor prefilled with day/time.
- Dragging previews a proposed change locally, then calls authoritative validation before save.
- A hard conflict prevents save and links to the conflicting entry/resource.
- A soft warning remains visible and may require an explicit acknowledgement if configured.
- Successful mutation replaces the affected entry from the server response and updates `lock_version`.
- A stale-write response preserves the user's draft, reloads current server state, and offers compare/reapply rather than discarding input.
- Undo is not client-only mutation reversal; it issues a new authorized command while the version remains editable.

### Accessible alternative

Provide a chronological list/table using the same filters and commands as the visual grid. The grid implements roving tabindex, named row/column headers, keyboard movement, non-color conflict icons/text, and an announcement region for validation results. Dragging is never the sole way to move an entry.

### Responsive behavior

- Desktop ≥1280 px: three-pane workspace.
- Tablet 768–1279 px: offerings drawer, grid, inspector sheet.
- Mobile <768 px: day-at-a-time agenda with add/edit sheets and a day picker. No compressed seven-column grid.

## Setup workflows

### Academic structure

Use a tree-and-inspector workspace. Unit-type rules are visible when adding/moving a node. Presets begin a configuration; they never lock terminology. Destructive moves preview affected descendants, groups, offerings, and scoped permissions.

### Faculty, rooms, and subjects

Use filterable tables with persistent URL filters, bulk selection only where a safe server command exists, and focused detail pages/sheets. Availability uses a weekly editor plus an explicit effective-date range and legend for available, unavailable, preferred, and avoid windows.

### Offerings

An offering-readiness view shows group, copied components, required sessions/minutes, eligible instructors, and room requirements. Readiness problems link to the exact setup screen that can resolve them.

## Versions, approvals, and publication

- Version list displays lineage, state, author, timestamps, approval progress, and published/superseded labels.
- Compare uses paired day lanes plus a structured change list: added, removed, moved, instructor changed, room changed, and content changed.
- Approval inbox is task-oriented: current step, timetable/period, submitter, age, conflicts/revalidation status, and decision actions.
- Decision dialogs use consistent verbs: `Approve`, `Request changes`, `Reject`.
- Publication is separate from approval and requires a confirmation summary of version, period, validation result, previous published version, and affected export availability.
- Historical approvals render snapshots, not live profile details.

The signature visual is an understated rectangular approval stamp containing decision, step label, actor snapshot, and timestamp. It appears in history and previews, not as decorative chrome throughout the app.

## Templates and asynchronous work

The Excel mapping flow is a guided sequence because arbitrary inference is explicitly out of scope:

1. upload and security scan;
2. choose worksheet;
3. select timetable range;
4. map day columns and time rows;
5. map schedule-cell output;
6. map placeholders and signatory slots;
7. validate and preview;
8. activate template version.

Each step shows the workbook preview beside mapping controls and saves a draft mapping. Invalid regions are explained in spreadsheet language. Queued inspection/export states use `pending`, `running`, `completed`, `failed`, and `cancelled`, with polling that backs off when the page is hidden. A failed run keeps its configuration and offers `Retry` when safe.

## Permissions, entitlements, and failures

| State | UI behavior |
|---|---|
| No permission | Omit mutation controls; retain authorized read view |
| Capability unavailable | Show locked feature entry only when useful for plan discovery; explain required capability |
| Numeric limit reached | Keep existing records readable; disable create and link to usage/remediation |
| Hard schedule conflict | Block save; group issues by time, resource, requirement, and workload |
| Soft warning | Permit according to rule configuration; record acknowledgement where required |
| Stale write | Preserve draft; display current value and compare/reapply options |
| Rate limited | Retain input and show retry timing |
| Async failure | Show stable error code, plain-language cause, retry/support path, and correlation ID |
| Empty setup | Name the next prerequisite action, such as `Add an academic year` |

Do not use a disabled control without an explanation. Error copy names the problem and the corrective action; it does not expose exception text.

## Form behavior

- Use Inertia `<Form>` for conventional create/update/delete workflows and Wayfinder `.form()` contracts.
- Use the Inertia HTTP client for scheduling validation and rapid commands; do not add Axios.
- Validate on submit by default. Use debounced server validation only for fields whose uniqueness or domain meaning cannot be evaluated locally.
- Preserve user input on 422, 409, 429, and recoverable network errors.
- Destructive confirmation names the exact object and consequence.
- Dates and times are edited in organization-local context; convert timestamps only at display boundaries.

## Accessibility and quality floor

- Meet WCAG 2.2 AA contrast, focus visibility, labeling, error association, and target-size requirements.
- Every page has one visible or screen-reader-accessible `h1` and a logical heading order.
- Dialogs/sheets trap and restore focus; async updates announce status without stealing focus.
- Do not rely on color alone for availability, state, or conflicts.
- Respect reduced motion; the time-rail selection and panel transitions become immediate.
- Virtualized lists/grids must retain keyboard navigation and accessible alternatives.
- Test 200% zoom, narrow viewport, long institutional names, translated labels, and Philippine date/time examples.

## Motion

Use one orchestrated movement: selecting an entry draws focus from its time-rail position to the inspector through a 120–160 ms highlight transition. Other motion is limited to functional disclosure, drag previews, and progress. No ambient dashboard animation.

## Frontend delivery sequence

1. Restore lint/format/type baseline.
2. Introduce semantic product tokens without changing auth behavior.
3. Replace starter repository/documentation navigation with permission-aware product navigation.
4. Build shared page header, context bar, status badge, data table, filter bar, empty/error/loading states, conflict list, and async-run status.
5. Build setup domains in backend dependency order: academic, resources, catalog, offerings.
6. Build the timetable read view and accessible agenda before drag-and-drop mutation.
7. Add schedule editor, validation/conflicts, optimistic locking, and exception workflows.
8. Add versions, compare, approvals, publication, templates/exports, subscriptions, and audit.
9. Add browser smoke tests and screenshot-based visual regression for critical states.

No frontend domain page should be marked complete until its backend policy, capability, public-ID contract, failure states, and browser path are implemented.
