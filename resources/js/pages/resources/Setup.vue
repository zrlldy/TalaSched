<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CircleGauge,
    Clock3,
    FlaskConical,
    GraduationCap,
    Layers3,
    Plus,
    School,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AddTeacherModal from '@/components/AddTeacherModal.vue';
import InputError from '@/components/InputError.vue';
import MinuteTimeInput from '@/components/MinuteTimeInput.vue';
import RecordDirectory from '@/components/RecordDirectory.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceSectionNav from '@/components/WorkspaceSectionNav.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { useWorkspaceSection } from '@/composables/useWorkspaceSection';
import { setup as academicSetup } from '@/routes/academic';
import { store as storeComponent } from '@/routes/catalog/components';
import {
    store as storeOffering,
    instructors as assignInstructor,
    status as updateOfferingStatus,
    components as addOfferingComponent,
} from '@/routes/catalog/offerings';
import { store as storeSubject } from '@/routes/catalog/subjects';
import { setup } from '@/routes/resources';
import { store as storeAvailability } from '@/routes/resources/availability';
import { store as storeBuilding } from '@/routes/resources/buildings';
import { store as storeFeature } from '@/routes/resources/features';
import { store as storeRoomType } from '@/routes/resources/room-types';
import { store as storeRoom } from '@/routes/resources/rooms';
import type { Organization } from '@/types';

type Option = { value: string; label: string };
type Faculty = {
    id: string;
    name: string;
    resource_id: string;
    employee_number: string | null;
    position: string | null;
    employment_type: string | null;
    maximum_daily_minutes: number | null;
    maximum_weekly_minutes: number | null;
    units: { id: string; name: string }[];
};
type Room = {
    id: string;
    resource_id: string;
    resource_name: string;
    code: string;
    name: string;
    building_code: string | null;
    room_type_code: string;
    capacity: number | null;
    delivery_mode: string;
    features: { code: string; quantity: number | null }[];
};
type Subject = {
    id: string;
    code: string;
    name: string;
    units: string | number | null;
    description: string | null;
    components: {
        kind: string;
        name: string;
        weekly_minutes: number;
        sessions_per_week: number;
        default_duration_minutes: number;
        minimum_room_capacity: number | null;
        delivery_mode: string;
        room_types: string[];
        features: { code: string; minimum_quantity: number | null }[];
    }[];
};
type Offering = {
    id: string;
    code: string | null;
    subject_id: string;
    subject_code: string;
    period_name: string;
    group_label: string;
    expected_enrollment: number;
    status: string;
    components_count: number;
    available_components: {
        kind: string;
        name: string;
        duration_minutes: number;
    }[];
    components: {
        id: string;
        name: string;
        instructors: { id: string; name: string }[];
    }[];
};

type Props = {
    faculty: Faculty[];
    buildings: { code: string; name: string; campus: string | null }[];
    roomTypes: { code: string; name: string }[];
    features: { code: string; name: string }[];
    rooms: Room[];
    subjects: Subject[];
    offerings: Offering[];
    periods: {
        id: string;
        name: string;
        kind: string;
        year_name: string;
        starts_on: string;
        ends_on: string;
    }[];
    groups: { id: string; label: string; year_name: string }[];
    units: { id: string; label: string; type: string }[];
    resources: { id: string; name: string; type: string; type_label: string }[];
    availabilityRules: {
        resource_name: string;
        kind: string;
        weekday: number;
        starts_at_minute: number;
        ends_at_minute: number;
        period_name: string | null;
        effective_from: string | null;
        effective_until: string | null;
        priority: number;
    }[];
    employmentTypes: Option[];
    deliveryModes: Option[];
    componentKinds: Option[];
    offeringStatuses: Option[];
    availabilityKinds: Option[];
    canManageResources: boolean;
    canManageCatalog: boolean;
};

const props = defineProps<Props>();
const page = usePage();
const organizationSlug = computed(
    () => page.props.currentOrganization?.slug ?? '',
);
const fieldClass = 'mt-1.5 h-10 min-w-0';
const selectClass =
    'mt-1.5 h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';

const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

const formatMinutes = (minutes: number | null) => {
    if (minutes === null) {
        return 'No limit';
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};

const { section, selectSection } = useWorkspaceSection(
    ['faculty', 'rooms', 'subjects', 'offerings', 'availability'],
    'faculty',
    'resources:' + organizationSlug.value,
);
const search = ref('');
const roomName = ref('');
const componentSubjectId = ref('');
const subjectRequirementsOpen = ref(false);
function prepareSubjectRequirements(subjectId: string): void {
    componentSubjectId.value = subjectId;
    subjectRequirementsOpen.value = true;
    search.value = '';
    selectSection('subjects');
}
const sections = computed(() => [
    { value: 'faculty', label: 'Faculty', count: props.faculty.length },
    { value: 'rooms', label: 'Rooms', count: props.rooms.length },
    { value: 'subjects', label: 'Subjects', count: props.subjects.length },
    { value: 'offerings', label: 'Offerings', count: props.offerings.length },
    { value: 'availability', label: 'Availability' },
]);
const descriptions: Record<string, string> = {
    faculty: 'Manage teaching staff and the time they can teach.',
    rooms: 'Manage teaching spaces, seating capacity, and room requirements.',
    subjects:
        'Create reusable subjects, then add their lecture or lab requirements.',
    offerings:
        'An offering connects a subject to a student group for an academic period.',
    availability:
        'Set when faculty and rooms are available, unavailable, or preferred.',
};
const matches = (...values: (string | null)[]) =>
    values.join(' ').toLowerCase().includes(search.value.trim().toLowerCase());
const filteredFaculty = computed(() =>
    props.faculty.filter((person) =>
        matches(person.name, person.employee_number, person.position),
    ),
);
const filteredRooms = computed(() =>
    props.rooms.filter((room) =>
        matches(room.name, room.code, room.building_code),
    ),
);
const filteredSubjects = computed(() =>
    props.subjects.filter((subject) => matches(subject.name, subject.code)),
);
const filteredOfferings = computed(() =>
    props.offerings.filter((offering) =>
        matches(
            offering.code,
            offering.subject_code,
            offering.group_label,
            offering.period_name,
        ),
    ),
);
const filteredAvailability = computed(() =>
    props.availabilityRules
        .map((rule, index) => ({ ...rule, id: String(index) }))
        .filter((rule) =>
            matches(rule.resource_name, rule.kind, rule.period_name),
        ),
);
const clockTime = (minutes: number): string =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

function facultyCreated(): void {
    search.value = '';
    selectSection('faculty');
}

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Resources & catalog',
                href: layoutProps.currentOrganization
                    ? setup(layoutProps.currentOrganization.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-5 p-4 sm:p-6">
        <Head
            :title="
                sections.find((item) => item.value === section)?.label ??
                'Resources'
            "
        />
        <WorkspacePageHeader
            :title="
                sections.find((item) => item.value === section)?.label ??
                'Resources'
            "
            section="Prepare your schedule"
            :description="descriptions[section]"
        />
        <WorkspaceSectionNav
            :sections="sections"
            :selected="section"
            label="Resource sections"
            @select="selectSection"
        />
        <div class="max-w-sm">
            <Label for="resource-search" class="sr-only"
                >Search this directory</Label
            >
            <Input
                id="resource-search"
                v-model="search"
                type="search"
                placeholder="Search by name or code..."
                class="h-11"
            />
        </div>
        <section
            v-show="section === 'faculty'"
            class="space-y-4"
            aria-label="Faculty"
        >
            <article class="min-w-0 rounded-lg border border-border/70 bg-card">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold">Faculty directory</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Primary units and load limits are visible before
                            scheduling.
                        </p>
                    </div>
                    <AddTeacherModal
                        v-if="canManageResources"
                        :organization-slug="organizationSlug"
                        :employment-types="employmentTypes"
                        :units="units"
                        @created="facultyCreated"
                    />
                </div>
                <RecordDirectory
                    :records="filteredFaculty"
                    :columns="[
                        { key: 'teacher', label: 'Teacher' },
                        { key: 'unit', label: 'Academic unit' },
                        { key: 'daily', label: 'Daily limit' },
                        { key: 'weekly', label: 'Weekly limit' },
                    ]"
                    label="Faculty directory"
                    row-test="faculty-row"
                    :empty="
                        faculty.length
                            ? 'No teachers match your search. Try another name or employee number.'
                            : 'No teachers yet. Add your first teacher to start building the teaching team.'
                    "
                >
                    <template #teacher="{ record }"
                        ><p class="font-medium">{{ record.name }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ record.employee_number || 'No employee number'
                            }}<span v-if="record.position">
                                / {{ record.position }}</span
                            >
                        </p></template
                    >
                    <template #unit="{ record }"
                        ><span class="text-muted-foreground">{{
                            record.units.map((unit) => unit.name).join(', ') ||
                            'Unassigned'
                        }}</span></template
                    >
                    <template #daily="{ record }"
                        ><span
                            class="font-mono"
                            data-test="daily-teaching-limit"
                            >{{
                                formatMinutes(record.maximum_daily_minutes)
                            }}</span
                        ></template
                    >
                    <template #weekly="{ record }"
                        ><span
                            class="font-mono"
                            data-test="weekly-teaching-limit"
                            >{{
                                formatMinutes(record.maximum_weekly_minutes)
                            }}</span
                        ></template
                    >
                </RecordDirectory>
            </article>
        </section>
        <section
            v-show="section === 'rooms'"
            class="space-y-4"
            aria-label="Rooms"
        >
            <article class="min-w-0 rounded-lg border border-border/70 bg-card">
                <div class="border-b border-border/70 px-5 py-4">
                    <h2 class="font-semibold">Room directory</h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        Find a teaching space by name, code, or building.
                    </p>
                </div>
                <RecordDirectory
                    :records="filteredRooms"
                    :columns="[
                        { key: 'room', label: 'Room' },
                        { key: 'building', label: 'Building / type' },
                        { key: 'capacity', label: 'Seats' },
                        { key: 'delivery', label: 'Delivery' },
                    ]"
                    label="Room directory"
                    :empty="
                        rooms.length
                            ? 'No rooms match your search. Try another name, code, or building.'
                            : 'No rooms yet. Add a room type, then your first teaching space.'
                    "
                >
                    <template #room="{ record }"
                        ><p class="font-medium">{{ record.name }}</p>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">
                            {{ record.code }}
                        </p></template
                    >
                    <template #building="{ record }"
                        >{{ record.building_code || 'No building' }} /
                        {{ record.room_type_code }}</template
                    >
                    <template #capacity="{ record }"
                        ><span class="font-mono">{{
                            record.capacity ?? 'Not set'
                        }}</span></template
                    >
                    <template #delivery="{ record }"
                        ><Badge variant="outline">{{
                            record.delivery_mode
                        }}</Badge></template
                    >
                </RecordDirectory>
            </article>
            <p
                v-if="roomTypes.length === 0 && canManageResources"
                class="rounded-md border border-warning/30 bg-warning/5 p-4 text-sm"
            >
                First add a room type, such as Classroom or Laboratory, using
                Room settings below.
            </p>
            <details
                v-if="canManageResources"
                :open="rooms.length === 0 && roomTypes.length > 0"
                class="group rounded-lg border border-border bg-card p-5"
            >
                <summary
                    class="cursor-pointer text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Add a room
                </summary>
                <div class="pt-5">
                    <div class="mb-5 flex items-start gap-3">
                        <div
                            class="rounded-lg bg-sky-500/15 p-2 text-sky-700 dark:text-sky-300"
                        >
                            <Building2 class="h-4 w-4" />
                        </div>
                        <div>
                            <h2 class="font-semibold">Add room</h2>
                            <p
                                class="mt-1 text-sm leading-5 text-muted-foreground"
                            >
                                Set the room's name, type, and number of seats.
                            </p>
                        </div>
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeRoom.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <ValidationSummary :errors="errors" class="mb-4" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <input
                                type="hidden"
                                name="resource_name"
                                :value="roomName"
                            />
                            <div>
                                <Label for="room-code">Room code</Label
                                ><Input
                                    id="room-code"
                                    name="code"
                                    :class="fieldClass"
                                    placeholder="SCI-101"
                                /><InputError :message="errors.code" />
                            </div>
                            <div>
                                <Label for="room-name">Display name</Label
                                ><Input
                                    id="room-name"
                                    v-model="roomName"
                                    name="name"
                                    :class="fieldClass"
                                    placeholder="Science Laboratory 1"
                                /><InputError :message="errors.name" />
                            </div>
                            <div>
                                <Label for="room-type-code">Room type</Label
                                ><select
                                    id="room-type-code"
                                    name="room_type_code"
                                    :class="selectClass"
                                >
                                    <option value="">Select type</option>
                                    <option
                                        v-for="type in roomTypes"
                                        :key="type.code"
                                        :value="type.code"
                                    >
                                        {{ type.code }} / {{ type.name }}
                                    </option></select
                                ><InputError :message="errors.room_type_code" />
                            </div>
                            <div>
                                <Label for="building-code">Building</Label
                                ><select
                                    id="building-code"
                                    name="building_code"
                                    :class="selectClass"
                                >
                                    <option value="">No building</option>
                                    <option
                                        v-for="building in buildings"
                                        :key="building.code"
                                        :value="building.code"
                                    >
                                        {{ building.code }} /
                                        {{ building.name }}
                                    </option></select
                                ><InputError :message="errors.building_code" />
                            </div>
                            <div>
                                <Label for="room-capacity">Capacity</Label
                                ><Input
                                    id="room-capacity"
                                    name="capacity"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="32"
                                /><InputError :message="errors.capacity" />
                            </div>
                            <div>
                                <Label for="delivery-mode">Delivery mode</Label
                                ><select
                                    id="delivery-mode"
                                    name="delivery_mode"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="option in deliveryModes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError :message="errors.delivery_mode" />
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Add room</Button
                        >
                    </Form>
                </div>
            </details>
            <details
                v-if="canManageResources"
                :open="roomTypes.length === 0"
                class="rounded-lg border bg-card p-5"
            >
                <summary class="cursor-pointer text-sm font-semibold">
                    Room settings: types, features &amp; buildings
                </summary>
                <div class="mt-4 grid min-w-0 gap-4 lg:grid-cols-3">
                    <article
                        class="min-w-0 rounded-lg border border-border/70 bg-card p-5"
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <School class="h-4 w-4 text-sky-600" />
                            <h2 class="font-semibold">Room type</h2>
                        </div>
                        <Form
                            @success="selectSection(section)"
                            v-bind="storeRoomType.form([organizationSlug])"
                            #default="{ errors, processing }"
                        >
                            <ValidationSummary :errors="errors" class="mb-4" />
                            <Label for="room-type-code-new">Code</Label
                            ><Input
                                id="room-type-code-new"
                                name="code"
                                :class="fieldClass"
                                placeholder="LAB"
                            /><InputError :message="errors.code" />
                            <Label class="mt-4 block" for="room-type-name-new"
                                >Name</Label
                            ><Input
                                id="room-type-name-new"
                                name="name"
                                :class="fieldClass"
                                placeholder="Laboratory"
                            /><InputError :message="errors.name" />
                            <Button
                                class="mt-4"
                                size="sm"
                                type="submit"
                                :disabled="processing"
                                ><Plus class="h-4 w-4" /> Add type</Button
                            >
                        </Form>
                    </article>
                    <article
                        class="min-w-0 rounded-lg border border-border/70 bg-card p-5"
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <FlaskConical class="h-4 w-4 text-amber-600" />
                            <h2 class="font-semibold">Room feature</h2>
                        </div>
                        <Form
                            @success="selectSection(section)"
                            v-bind="storeFeature.form([organizationSlug])"
                            #default="{ errors, processing }"
                        >
                            <ValidationSummary :errors="errors" class="mb-4" />
                            <Label for="feature-code-new">Code</Label
                            ><Input
                                id="feature-code-new"
                                name="code"
                                :class="fieldClass"
                                placeholder="PROJECTOR"
                            /><InputError :message="errors.code" />
                            <Label class="mt-4 block" for="feature-name-new"
                                >Name</Label
                            ><Input
                                id="feature-name-new"
                                name="name"
                                :class="fieldClass"
                                placeholder="Projector"
                            /><InputError :message="errors.name" />
                            <Button
                                class="mt-4"
                                size="sm"
                                type="submit"
                                :disabled="processing"
                                ><Plus class="h-4 w-4" /> Add feature</Button
                            >
                        </Form>
                    </article>
                    <article
                        class="min-w-0 rounded-lg border border-border/70 bg-card p-5"
                    >
                        <div class="mb-4 flex items-center gap-3">
                            <Building2 class="h-4 w-4 text-lime-600" />
                            <h2 class="font-semibold">Building</h2>
                        </div>
                        <Form
                            @success="selectSection(section)"
                            v-bind="storeBuilding.form([organizationSlug])"
                            #default="{ errors, processing }"
                        >
                            <ValidationSummary :errors="errors" class="mb-4" />
                            <Label for="building-code-new">Code</Label
                            ><Input
                                id="building-code-new"
                                name="code"
                                :class="fieldClass"
                                placeholder="SCI"
                            /><InputError :message="errors.code" />
                            <Label class="mt-4 block" for="building-name-new"
                                >Name</Label
                            ><Input
                                id="building-name-new"
                                name="name"
                                :class="fieldClass"
                                placeholder="Science Building"
                            /><InputError :message="errors.name" />
                            <Label class="mt-4 block" for="campus-id"
                                >Campus / unit</Label
                            ><select
                                id="campus-id"
                                name="campus_id"
                                :class="selectClass"
                            >
                                <option value="">No campus link</option>
                                <option
                                    v-for="unit in units"
                                    :key="unit.id"
                                    :value="unit.id"
                                >
                                    {{ unit.label }}
                                </option></select
                            ><InputError :message="errors.campus_id" />
                            <Button
                                class="mt-4"
                                size="sm"
                                type="submit"
                                :disabled="processing"
                                ><Plus class="h-4 w-4" /> Add building</Button
                            >
                        </Form>
                    </article>
                </div>
            </details>
        </section>
        <section
            v-show="section === 'subjects'"
            class="space-y-4"
            aria-label="Subjects"
        >
            <article class="min-w-0 rounded-lg border border-border/70 bg-card">
                <div class="border-b border-border/70 px-5 py-4">
                    <h2 class="font-semibold">Subject catalog</h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        Components currently attached to each subject.
                    </p>
                </div>
                <RecordDirectory
                    :records="filteredSubjects"
                    :columns="[
                        { key: 'subject', label: 'Subject' },
                        { key: 'units', label: 'Units' },
                        { key: 'requirements', label: 'Teaching requirements' },
                    ]"
                    label="Subject catalog"
                    :empty="
                        subjects.length
                            ? 'No subjects match your search. Try another name or code.'
                            : 'No subjects yet. Add a subject, then its lecture or lab requirements.'
                    "
                >
                    <template #subject="{ record }"
                        ><p class="font-medium">{{ record.name }}</p>
                        <p class="mt-1 font-mono text-xs text-muted-foreground">
                            {{ record.code }}
                        </p></template
                    >
                    <template #units="{ record }"
                        ><span class="font-mono">{{
                            record.units ?? 'Not set'
                        }}</span></template
                    >
                    <template #requirements="{ record }"
                        ><div
                            v-if="record.components.length"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="component in record.components"
                                :key="component.kind + component.name"
                                class="rounded-md bg-muted px-2 py-1 text-xs"
                                >{{ component.name }} /
                                {{ component.weekly_minutes }} min per
                                week</span
                            >
                        </div>
                        <span v-else class="text-warning"
                            >Add lecture or lab requirements before
                            scheduling</span
                        ></template
                    >
                </RecordDirectory>
            </article>
            <details
                v-if="canManageCatalog"
                :open="subjects.length === 0"
                class="group rounded-lg border border-border bg-card p-5"
            >
                <summary
                    class="cursor-pointer text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Add a subject
                </summary>
                <div class="pt-5">
                    <div class="mb-5 flex items-start gap-3">
                        <div
                            class="rounded-lg bg-amber-500/15 p-2 text-amber-700 dark:text-amber-300"
                        >
                            <GraduationCap class="h-4 w-4" />
                        </div>
                        <div>
                            <h2 class="font-semibold">Add subject</h2>
                            <p
                                class="mt-1 text-sm leading-5 text-muted-foreground"
                            >
                                Create the reusable catalog record first.
                            </p>
                        </div>
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeSubject.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <ValidationSummary :errors="errors" class="mb-4" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label for="subject-code">Subject code</Label
                                ><Input
                                    id="subject-code"
                                    name="code"
                                    :class="fieldClass"
                                    placeholder="CS-101"
                                /><InputError :message="errors.code" />
                            </div>
                            <div>
                                <Label for="subject-name">Name</Label
                                ><Input
                                    id="subject-name"
                                    name="name"
                                    :class="fieldClass"
                                    placeholder="Programming I"
                                /><InputError :message="errors.name" />
                            </div>
                            <div>
                                <Label for="subject-units">Units</Label
                                ><Input
                                    id="subject-units"
                                    name="units"
                                    type="number"
                                    step="0.5"
                                    :class="fieldClass"
                                    placeholder="3"
                                /><InputError :message="errors.units" />
                            </div>
                            <div>
                                <Label for="subject-description"
                                    >Description</Label
                                ><Input
                                    id="subject-description"
                                    name="description"
                                    :class="fieldClass"
                                    placeholder="Core programming course"
                                /><InputError :message="errors.description" />
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Add subject</Button
                        >
                    </Form>
                </div>
            </details>
            <details
                v-if="canManageCatalog && subjects.length > 0"
                :open="subjectRequirementsOpen"
                class="group rounded-lg border border-border bg-card p-5"
            >
                <summary
                    class="cursor-pointer text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Add lecture or lab requirements
                </summary>
                <div class="pt-5">
                    <div class="mb-5 flex items-start gap-3">
                        <div
                            class="rounded-lg bg-sky-500/15 p-2 text-sky-700 dark:text-sky-300"
                        >
                            <CircleGauge class="h-4 w-4" />
                        </div>
                        <div>
                            <h2 class="font-semibold">Add component</h2>
                            <p
                                class="mt-1 text-sm leading-5 text-muted-foreground"
                            >
                                Set the weekly teaching time, session length,
                                and room needs.
                            </p>
                        </div>
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeComponent.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <ValidationSummary :errors="errors" class="mb-4" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <Label for="component-subject">Subject</Label
                                ><select
                                    id="component-subject"
                                    v-model="componentSubjectId"
                                    name="subject_id"
                                    :class="selectClass"
                                >
                                    <option value="">Select subject</option>
                                    <option
                                        v-for="subject in subjects"
                                        :key="subject.id"
                                        :value="subject.id"
                                    >
                                        {{ subject.code }} / {{ subject.name }}
                                    </option></select
                                ><InputError :message="errors.subject_id" />
                            </div>
                            <div>
                                <Label for="component-kind">Kind</Label
                                ><select
                                    id="component-kind"
                                    name="kind"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="option in componentKinds"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError :message="errors.kind" />
                            </div>
                            <div>
                                <Label for="component-name">Name</Label
                                ><Input
                                    id="component-name"
                                    name="name"
                                    :class="fieldClass"
                                    placeholder="Lecture"
                                /><InputError :message="errors.name" />
                            </div>
                            <div>
                                <Label for="weekly-minutes"
                                    >Weekly minutes</Label
                                ><Input
                                    id="weekly-minutes"
                                    name="weekly_minutes"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="180"
                                /><InputError
                                    :message="errors.weekly_minutes"
                                />
                            </div>
                            <div>
                                <Label for="sessions">Sessions / week</Label
                                ><Input
                                    id="sessions"
                                    name="sessions_per_week"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="2"
                                /><InputError
                                    :message="errors.sessions_per_week"
                                />
                            </div>
                            <div>
                                <Label for="duration"
                                    >Duration / session (minutes)</Label
                                ><Input
                                    id="duration"
                                    name="default_duration_minutes"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="90"
                                /><InputError
                                    :message="errors.default_duration_minutes"
                                />
                            </div>
                            <div>
                                <Label for="component-delivery"
                                    >Delivery mode</Label
                                ><select
                                    id="component-delivery"
                                    name="delivery_mode"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="option in deliveryModes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError :message="errors.delivery_mode" />
                            </div>
                            <div>
                                <Label for="component-capacity"
                                    >Minimum room capacity</Label
                                ><Input
                                    id="component-capacity"
                                    name="minimum_room_capacity"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="20"
                                /><InputError
                                    :message="errors.minimum_room_capacity"
                                />
                            </div>
                            <div>
                                <Label for="component-room-type"
                                    >Required room type</Label
                                ><select
                                    id="component-room-type"
                                    name="room_type_code"
                                    :class="selectClass"
                                >
                                    <option value="">No room type</option>
                                    <option
                                        v-for="type in roomTypes"
                                        :key="type.code"
                                        :value="type.code"
                                    >
                                        {{ type.code }} / {{ type.name }}
                                    </option></select
                                ><InputError :message="errors.room_type_code" />
                            </div>
                            <div>
                                <Label for="component-feature"
                                    >Required feature</Label
                                ><select
                                    id="component-feature"
                                    name="feature_code"
                                    :class="selectClass"
                                >
                                    <option value="">No feature</option>
                                    <option
                                        v-for="feature in features"
                                        :key="feature.code"
                                        :value="feature.code"
                                    >
                                        {{ feature.code }} / {{ feature.name }}
                                    </option></select
                                ><InputError :message="errors.feature_code" />
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Add component</Button
                        >
                    </Form>
                </div>
            </details>
        </section>
        <section
            v-show="section === 'offerings'"
            class="space-y-4"
            aria-label="Offerings"
        >
            <article class="min-w-0 rounded-lg border border-border/70 bg-card">
                <div class="border-b border-border/70 px-5 py-4">
                    <h2 class="font-semibold">Offering directory</h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        See which subjects and student groups are prepared for
                        each period.
                    </p>
                </div>
                <div class="grid items-start gap-3 p-3 xl:grid-cols-2">
                    <div
                        v-for="offering in filteredOfferings"
                        :key="offering.id"
                        class="min-w-0 rounded-lg border border-border/70 bg-background p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 font-medium break-words">
                                {{ offering.code || offering.subject_code }}
                            </p>
                            <Badge class="shrink-0" variant="outline">{{
                                offering.status
                            }}</Badge>
                        </div>
                        <p class="mt-2 text-sm break-words">
                            {{ offering.subject_code }} /
                            {{ offering.group_label }}
                        </p>
                        <p
                            class="mt-1 text-xs break-words text-muted-foreground"
                        >
                            {{ offering.period_name }} ·
                            {{ offering.components_count }} component(s) ·
                            {{ offering.expected_enrollment }} seats
                        </p>
                        <details
                            :open="offering.components.length === 0"
                            class="mt-3 rounded-md border border-border bg-background p-3"
                        >
                            <summary class="cursor-pointer text-sm font-medium">
                                Teaching team and readiness
                            </summary>
                            <div class="mt-3 grid gap-4">
                                <p class="text-xs text-muted-foreground">
                                    Only active offerings can be added to a
                                    timetable. Assign an eligible instructor to
                                    each teaching component.
                                </p>
                                <Form
                                    v-if="canManageCatalog"
                                    v-bind="
                                        updateOfferingStatus.form(
                                            organizationSlug,
                                        )
                                    "
                                    :error-bag="`status-${offering.id}`"
                                    v-slot="{ errors, processing }"
                                    class="grid gap-2"
                                    @success="selectSection(section)"
                                >
                                    <input
                                        type="hidden"
                                        name="offering_id"
                                        :value="offering.id"
                                    />
                                    <Label :for="`status-${offering.id}`"
                                        >Offering status</Label
                                    >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <select
                                            :id="`status-${offering.id}`"
                                            name="status"
                                            :value="offering.status"
                                            :class="selectClass"
                                            class="sm:w-auto"
                                        >
                                            <option
                                                v-for="option in offeringStatuses"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            :disabled="processing"
                                            >Update status</Button
                                        >
                                    </div>
                                    <ValidationSummary :errors="errors" />
                                </Form>
                                <div
                                    v-for="component in offering.components"
                                    :key="component.id"
                                    class="grid gap-2 border-t border-border pt-3"
                                >
                                    <h3 class="text-sm font-medium">
                                        {{ component.name }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            component.instructors
                                                .map(
                                                    (instructor) =>
                                                        instructor.name,
                                                )
                                                .join(', ') ||
                                            'No eligible instructors assigned.'
                                        }}
                                    </p>
                                    <Form
                                        v-if="canManageCatalog"
                                        v-bind="
                                            assignInstructor.form(
                                                organizationSlug,
                                            )
                                        "
                                        :error-bag="`instructor-${component.id}`"
                                        v-slot="{ errors, processing }"
                                        class="grid gap-2"
                                        @success="selectSection(section)"
                                    >
                                        <input
                                            type="hidden"
                                            name="offering_component_id"
                                            :value="component.id"
                                        />
                                        <div
                                            class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]"
                                        >
                                            <div>
                                                <Label
                                                    :for="`instructor-${component.id}`"
                                                    >Eligible instructor</Label
                                                >
                                                <select
                                                    :id="`instructor-${component.id}`"
                                                    name="faculty_profile_id"
                                                    required
                                                    :class="selectClass"
                                                >
                                                    <option value="">
                                                        Choose faculty
                                                    </option>
                                                    <option
                                                        v-for="profile in faculty.filter(
                                                            (profile) =>
                                                                !component.instructors.some(
                                                                    (
                                                                        instructor,
                                                                    ) =>
                                                                        instructor.id ===
                                                                        profile.id,
                                                                ),
                                                        )"
                                                        :key="profile.id"
                                                        :value="profile.id"
                                                    >
                                                        {{ profile.name }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <Label
                                                    :for="`load-${component.id}`"
                                                    >Load share (%)</Label
                                                ><Input
                                                    :id="`load-${component.id}`"
                                                    type="number"
                                                    name="load_percentage"
                                                    min="1"
                                                    max="100"
                                                    :default-value="100"
                                                    required
                                                    :class="fieldClass"
                                                />
                                            </div>
                                        </div>
                                        <ValidationSummary :errors="errors" />
                                        <Button
                                            class="justify-self-start"
                                            type="submit"
                                            variant="outline"
                                            :disabled="
                                                processing ||
                                                faculty.length === 0
                                            "
                                            >Assign instructor</Button
                                        >
                                    </Form>
                                </div>
                                <p
                                    v-if="offering.components.length === 0"
                                    class="text-sm text-warning"
                                >
                                    This offering has no teaching components.
                                    Add a lecture or lab below, then assign an
                                    instructor.
                                </p>
                                <Form
                                    v-if="
                                        canManageCatalog &&
                                        offering.available_components.length
                                    "
                                    v-bind="
                                        addOfferingComponent.form(
                                            organizationSlug,
                                        )
                                    "
                                    :error-bag="`offering-component-${offering.id}`"
                                    #default="{ errors, processing }"
                                    class="grid gap-3 border-t pt-3"
                                >
                                    <input
                                        type="hidden"
                                        name="offering_id"
                                        :value="offering.id"
                                    />
                                    <p class="text-sm text-muted-foreground">
                                        Include these subject requirements in
                                        this offering:
                                    </p>
                                    <ul class="space-y-1 text-sm">
                                        <li
                                            v-for="component in offering.available_components"
                                            :key="
                                                component.kind + component.name
                                            "
                                        >
                                            {{ component.name }} /
                                            {{ component.duration_minutes }}
                                            minutes per session
                                        </li>
                                    </ul>
                                    <ValidationSummary :errors="errors" />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        class="justify-self-start"
                                        :disabled="processing"
                                        >{{
                                            processing
                                                ? 'Adding...'
                                                : 'Add subject requirements'
                                        }}</Button
                                    >
                                </Form>
                                <div
                                    v-else-if="offering.components.length === 0"
                                    class="space-y-2"
                                >
                                    <p class="text-sm text-muted-foreground">
                                        This subject needs lecture or lab
                                        requirements first. After adding them,
                                        return here to include them in this
                                        offering.
                                    </p>
                                    <Button
                                        v-if="canManageCatalog"
                                        type="button"
                                        variant="outline"
                                        @click="
                                            prepareSubjectRequirements(
                                                offering.subject_id,
                                            )
                                        "
                                        >Set up subject requirements</Button
                                    >
                                    <p
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        Ask your catalog administrator to add
                                        the requirements.
                                    </p>
                                </div>
                            </div>
                        </details>
                    </div>
                    <p
                        v-if="filteredOfferings.length === 0"
                        class="text-sm leading-5 text-muted-foreground"
                    >
                        No offerings match this search. Create one or try
                        another search.
                    </p>
                </div>
            </article>
            <WorkspaceState
                v-if="
                    canManageCatalog &&
                    (!periods.length || !groups.length || !subjects.length)
                "
                variant="empty"
                title="Prepare the offering details first"
                description="An offering needs an academic period, a student group, and a subject with teaching requirements."
            >
                <template #action
                    ><Button as-child variant="outline"
                        ><Link :href="academicSetup(organizationSlug)"
                            >Review academic setup</Link
                        ></Button
                    ><Button
                        variant="outline"
                        @click="selectSection('subjects')"
                        >Review subjects</Button
                    ></template
                >
            </WorkspaceState>
            <details
                v-if="
                    canManageCatalog &&
                    periods.length > 0 &&
                    groups.length > 0 &&
                    subjects.length > 0
                "
                :open="offerings.length === 0"
                class="group rounded-lg border border-border bg-card p-5"
            >
                <summary
                    class="cursor-pointer text-sm font-semibold focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Create an offering
                </summary>
                <div class="pt-5">
                    <div class="mb-5 flex items-start gap-3">
                        <div
                            class="rounded-lg bg-lime-500/15 p-2 text-lime-700 dark:text-lime-300"
                        >
                            <Layers3 class="h-4 w-4" />
                        </div>
                        <div>
                            <h2 class="font-semibold">Create offering</h2>
                            <p
                                class="mt-1 text-sm leading-5 text-muted-foreground"
                            >
                                Choose a period and year-matched student group.
                            </p>
                        </div>
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeOffering.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <ValidationSummary :errors="errors" class="mb-4" />
                        <div class="space-y-4">
                            <div>
                                <Label for="offering-period"
                                    >Academic period</Label
                                ><select
                                    id="offering-period"
                                    name="period_id"
                                    :class="selectClass"
                                >
                                    <option value="">Select period</option>
                                    <option
                                        v-for="period in periods"
                                        :key="period.id"
                                        :value="period.id"
                                    >
                                        {{ period.year_name }} /
                                        {{ period.name }}
                                    </option></select
                                ><InputError :message="errors.period_id" />
                            </div>
                            <div>
                                <Label for="offering-subject">Subject</Label
                                ><select
                                    id="offering-subject"
                                    name="subject_id"
                                    :class="selectClass"
                                >
                                    <option value="">Select subject</option>
                                    <option
                                        v-for="subject in subjects"
                                        :key="subject.id"
                                        :value="subject.id"
                                    >
                                        {{ subject.code }} / {{ subject.name }}
                                    </option></select
                                ><InputError :message="errors.subject_id" />
                            </div>
                            <div>
                                <Label for="offering-group">Student group</Label
                                ><select
                                    id="offering-group"
                                    name="student_group_id"
                                    :class="selectClass"
                                >
                                    <option value="">Select group</option>
                                    <option
                                        v-for="group in groups"
                                        :key="group.id"
                                        :value="group.id"
                                    >
                                        {{ group.year_name }} /
                                        {{ group.label }}
                                    </option></select
                                ><InputError
                                    :message="errors.student_group_id"
                                />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label for="offering-code"
                                        >Offering code</Label
                                    ><Input
                                        id="offering-code"
                                        name="code"
                                        :class="fieldClass"
                                        placeholder="CS-101-A"
                                    /><InputError :message="errors.code" />
                                </div>
                                <div>
                                    <Label for="enrollment"
                                        >Expected enrollment</Label
                                    ><Input
                                        id="enrollment"
                                        name="expected_enrollment"
                                        type="number"
                                        :class="fieldClass"
                                        placeholder="30"
                                    /><InputError
                                        :message="errors.expected_enrollment"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label for="offering-status">Status</Label
                                ><select
                                    id="offering-status"
                                    name="status"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="option in offeringStatuses"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError :message="errors.status" />
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Create offering</Button
                        >
                    </Form>
                </div>
            </details>
        </section>
        <section
            v-show="section === 'availability'"
            class="space-y-4"
            aria-label="Availability"
        >
            <p class="text-sm text-muted-foreground">
                Times use
                {{
                    page.props.organizationTimezone ||
                    "your organization's local timezone"
                }}.
            </p>
            <article class="min-w-0 rounded-lg border border-border/70 bg-card">
                <div class="border-b border-border/70 px-5 py-4">
                    <h2 class="font-semibold">Saved availability rules</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Review the hours and period overrides used when checking
                        your timetable.
                    </p>
                </div>
                <RecordDirectory
                    :records="filteredAvailability"
                    :columns="[
                        { key: 'resource', label: 'Resource' },
                        { key: 'hours', label: 'Weekly hours' },
                        { key: 'kind', label: 'Rule' },
                        { key: 'period', label: 'Applies to' },
                    ]"
                    label="Saved availability rules"
                    row-test="availability-row"
                    :empty="
                        availabilityRules.length
                            ? 'No availability rules match your search.'
                            : 'No availability rules yet. Add available or unavailable hours below.'
                    "
                >
                    <template #resource="{ record }"
                        ><span class="font-medium">{{
                            record.resource_name
                        }}</span></template
                    >
                    <template #hours="{ record }"
                        ><p>
                            {{
                                weekdays.find(
                                    (day) => day.value === record.weekday,
                                )?.label
                            }}
                        </p>
                        <p class="mt-1 font-mono text-xs">
                            {{ clockTime(record.starts_at_minute) }} -
                            {{ clockTime(record.ends_at_minute) }}
                        </p></template
                    >
                    <template #kind="{ record }"
                        ><Badge variant="outline">{{ record.kind }}</Badge>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Priority {{ record.priority }}
                        </p></template
                    >
                    <template #period="{ record }"
                        ><p>
                            {{ record.period_name || 'All academic periods' }}
                        </p>
                        <p
                            v-if="
                                record.effective_from || record.effective_until
                            "
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ record.effective_from || 'Any start date' }} /
                            {{ record.effective_until || 'No end date' }}
                        </p></template
                    >
                </RecordDirectory>
            </article>
            <template v-if="canManageResources"
                ><article
                    class="min-w-0 rounded-lg border border-border/70 bg-card p-5"
                >
                    <div
                        class="mb-5 flex items-start gap-3 rounded-lg bg-muted/50 px-4 py-3"
                    >
                        <Clock3 class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
                        <p class="text-xs leading-5 text-muted-foreground">
                            Choose the start and end time in your organization's
                            timezone. Use a period override when these hours
                            apply to one academic period.
                        </p>
                    </div>
                    <Form
                        @success="selectSection(section)"
                        v-bind="storeAvailability.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <ValidationSummary :errors="errors" class="mb-4" />
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div class="xl:col-span-2">
                                <Label for="availability-resource"
                                    >Resource</Label
                                ><select
                                    id="availability-resource"
                                    name="resource_id"
                                    :class="selectClass"
                                >
                                    <option value="">Select resource</option>
                                    <option
                                        v-for="resource in resources"
                                        :key="resource.id"
                                        :value="resource.id"
                                    >
                                        {{ resource.type_label }} /
                                        {{ resource.name }}
                                    </option></select
                                ><InputError :message="errors.resource_id" />
                            </div>
                            <div class="xl:col-span-2">
                                <Label for="availability-period"
                                    >Period override</Label
                                ><select
                                    id="availability-period"
                                    name="period_id"
                                    :class="selectClass"
                                >
                                    <option value="">Global rule</option>
                                    <option
                                        v-for="period in periods"
                                        :key="period.id"
                                        :value="period.id"
                                    >
                                        {{ period.year_name }} /
                                        {{ period.name }}
                                    </option></select
                                ><InputError :message="errors.period_id" />
                            </div>
                            <div>
                                <Label for="availability-kind">Rule kind</Label
                                ><select
                                    id="availability-kind"
                                    name="kind"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="option in availabilityKinds"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError :message="errors.kind" />
                            </div>
                            <div>
                                <Label for="weekday">Weekday</Label
                                ><select
                                    id="weekday"
                                    name="weekday"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="weekday in weekdays"
                                        :key="weekday.value"
                                        :value="weekday.value"
                                    >
                                        {{ weekday.label }}
                                    </option></select
                                ><InputError :message="errors.weekday" />
                            </div>
                            <div>
                                <Label for="starts-at">Start time</Label
                                ><MinuteTimeInput
                                    id="starts-at"
                                    name="starts_at_minute"
                                    required
                                    :class="fieldClass"
                                /><InputError
                                    :message="errors.starts_at_minute"
                                />
                            </div>
                            <div>
                                <Label for="ends-at">End time</Label
                                ><MinuteTimeInput
                                    id="ends-at"
                                    name="ends_at_minute"
                                    allow-end-of-day
                                    required
                                    :class="fieldClass"
                                /><InputError
                                    :message="errors.ends_at_minute"
                                />
                            </div>
                            <div>
                                <Label for="effective-from"
                                    >Effective from</Label
                                ><Input
                                    id="effective-from"
                                    name="effective_from"
                                    type="date"
                                    :class="fieldClass"
                                /><InputError
                                    :message="errors.effective_from"
                                />
                            </div>
                            <div>
                                <Label for="effective-until"
                                    >Effective until</Label
                                ><Input
                                    id="effective-until"
                                    name="effective_until"
                                    type="date"
                                    :class="fieldClass"
                                /><InputError
                                    :message="errors.effective_until"
                                />
                            </div>
                            <div>
                                <Label for="priority">Priority</Label
                                ><Input
                                    id="priority"
                                    name="priority"
                                    type="number"
                                    min="0"
                                    :class="fieldClass"
                                    :default-value="0"
                                /><InputError :message="errors.priority" />
                                <p
                                    class="mt-1 text-[11px] leading-4 text-muted-foreground"
                                >
                                    Use 0 for the normal rule; higher values
                                    take precedence.
                                </p>
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Add availability
                            rule</Button
                        >
                    </Form>
                </article></template
            >
            <WorkspaceState
                v-else
                variant="authorization"
                title="Availability is managed by your administrator"
                description="Ask your scheduling administrator to update teaching hours or room availability."
            />
        </section>
    </div>
</template>
