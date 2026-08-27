# Talasched Development Instructions

## Design System

When implementing or modifying Talasched UI, read:

- `docs/design/TALASCHED_DESIGN_SYSTEM.md`
- `docs/design/RAYCAST_REFERENCE.md`

The Raycast document is a DESIGN REFERENCE, not a specification to copy literally.

Talasched should borrow Raycast's:
- compact information density
- surface hierarchy
- hairline borders
- restrained border radii
- command-palette interaction model
- keyboard-first workflows
- compact rows
- pill filters
- semantic status colors
- minimal use of shadows
- strong typography hierarchy

Do NOT copy:
- Raycast branding
- Raycast red hero stripes
- Raycast logo/wordmark
- Raycast-specific marketing layouts
- dark-mode-only behavior
- white as the only primary CTA
- extension-store-specific components

Talasched must maintain its own visual identity.

## Talasched UI Philosophy

Talasched is a professional scheduling workspace.

The UI should feel:
- precise
- fast
- clean
- dense but readable
- modern
- keyboard-friendly
- suitable for prolonged administrative use

Prefer productivity-software patterns over generic admin-dashboard patterns.

Avoid:
- oversized cards
- excessive rounded corners
- large empty spaces
- unnecessary gradients
- excessive shadows
- dashboard-style card grids when a table/list is more appropriate

## Core Layout

For scheduling workflows, prefer:

Sidebar → Main Workspace → Contextual Inspector

The timetable should be treated as the primary workspace, not as a small widget inside a dashboard.

Example:

┌─────────────┬──────────────────────────┬──────────────────┐
│ Sidebar     │ Main Timetable           │ Inspector        │
│             │                          │                  │
│ Dashboard   │ Schedule workspace       │ Selected class   │
│ Timetable   │                          │ Faculty          │
│ Faculty     │                          │ Room             │
│ Rooms       │                          │ Time             │
│ Subjects    │                          │ Conflicts        │
└─────────────┴──────────────────────────┴──────────────────┘

## Surface System

Prefer visual hierarchy through surface contrast and borders rather than shadows.

Use roughly:

- canvas
- surface
- elevated surface
- active/selected surface

Cards and panels should generally use:
- 1px subtle borders
- 6–10px border radius
- 16–24px internal padding

Avoid large drop shadows unless required for floating overlays.

## Spacing

Use an 8px-oriented spacing system:

- 4px: very tight
- 8px: small gap
- 12px: compact controls
- 16px: standard component spacing
- 24px: panel/card padding
- 32px: section spacing

Do not use Raycast's 96px marketing-page section spacing inside the application UI.

## Typography

Use a clear compact hierarchy.

Typical application sizes:

- Page title: 24–28px
- Section heading: 18–20px
- Body: 14–16px
- Metadata: 12–13px
- Button: 14px

Prefer medium weight for headings instead of excessively bold typography.

## Radius

Use restrained rounding:

- 4px: badges/keycaps
- 6px: compact rows
- 8px: buttons/inputs
- 10px: cards/panels
- 12–16px: modals or large surfaces
- full radius: pills only

Do not use `rounded-2xl` or `rounded-3xl` everywhere.

## Semantic Colors

Color must communicate meaning.

Recommended semantics:

- blue: selection/information
- green: available/success/published
- yellow: warning/pending
- orange: attention
- red: conflict/error/destructive
- purple: version/special scheduling state

Never rely on color alone. Pair colors with icons, text, or labels.

## Command Palette

Talasched should support a global command interface inspired by Raycast.

Typical shortcut:

`⌘ K` / `Ctrl K`

Possible commands:
- Create schedule
- Add faculty
- Add room
- Add subject
- Open timetable
- Switch organization
- Find available room
- Find available faculty
- Show conflicts
- Publish timetable
- Export timetable

Commands should also be context-aware where practical.

## Keyboard Interaction

Scheduling workflows should support efficient keyboard interaction.

Examples:

- `⌘/Ctrl + K`: command palette
- `⌘/Ctrl + S`: save
- `⌘/Ctrl + Z`: undo
- `⌘/Ctrl + Shift + Z`: redo
- `Esc`: close/cancel
- `Delete`: remove selected schedule item

Do not implement shortcuts that conflict with browser/system behavior without a strong reason.

## Filters

Prefer compact pill filters or segmented controls for common schedule filters.

Examples:

Faculty:
[ All ] [ BSIT ] [ BSCS ]

Year:
[ All ] [ 1st ] [ 2nd ] [ 3rd ] [ 4th ]

Status:
[ All ] [ Conflicts ] [ Unassigned ] [ Draft ] [ Published ]

Use dropdowns for large or searchable datasets.

## Lists and Tables

Talasched can contain hundreds or thousands of:
- faculty
- rooms
- subjects
- courses
- sections
- schedules

Do not represent every record as a large card.

Prefer compact:
- tables
- list rows
- searchable lists
- virtualized lists where appropriate

## Light and Dark Themes

Unlike the Raycast reference, Talasched should support both light and dark interfaces where practical.

Both themes should preserve:
- hierarchy
- border visibility
- semantic colors
- readable contrast

Do not build components using hardcoded dark-only colors.

## Responsive Behavior

Desktop is the primary scheduling environment.

Desktop:
- sidebar + workspace + optional inspector

Tablet:
- collapse inspector when necessary
- sidebar can become drawer

Mobile:
- prioritize viewing/searching
- editing workflows may become stacked
- do not try to squeeze a full desktop timetable into a narrow viewport

## Implementation Rule

Before creating a new UI pattern, check whether the requirement can be satisfied using the existing Talasched design tokens and components.

Do not introduce one-off styles unless necessary.

When modifying existing pages, reuse and improve shared components instead of duplicating styling.

## Final Rule

Raycast is an interaction and visual-density reference.

Talasched must look like Talasched.