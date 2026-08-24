<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CircleGauge,
    Clock3,
    FlaskConical,
    GraduationCap,
    Layers3,
    Plus,
    School,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SetupRail from '@/components/SetupRail.vue';
import type { SetupRailStep } from '@/components/SetupRail.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as storeComponent } from '@/routes/catalog/components';
import { store as storeOffering } from '@/routes/catalog/offerings';
import { store as storeSubject } from '@/routes/catalog/subjects';
import { setup } from '@/routes/resources';
import { store as storeAvailability } from '@/routes/resources/availability';
import { store as storeBuilding } from '@/routes/resources/buildings';
import { store as storeFaculty } from '@/routes/resources/faculty';
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
    subject_code: string;
    period_name: string;
    group_label: string;
    expected_enrollment: number;
    status: string;
    components_count: number;
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
        return 'Not set';
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
};

const resourceCount = computed(() => props.resources.length);

const resourceSetupSteps = computed<SetupRailStep[]>(() => [
    {
        label: 'People & places',
        description: 'Add faculty and rooms',
        href: '#resource-registry',
        complete: props.faculty.length + props.rooms.length > 0,
    },
    {
        label: 'Course catalog',
        description: 'Add subjects and components',
        href: '#catalog',
        complete: props.subjects.length > 0,
    },
    {
        label: 'Offerings',
        description: 'Match courses to groups',
        href: '#catalog',
        complete: props.offerings.length > 0,
    },
    {
        label: 'Availability',
        description: 'Set when resources can be used',
        href: '#availability',
        complete: false,
    },
]);

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
    <Head title="Resources & catalog" />

    <div class="flex min-w-0 flex-col gap-8 pb-8">
        <header
            class="relative overflow-hidden rounded-2xl border border-border/70 bg-[#13211f] px-4 py-6 text-white shadow-sm sm:px-6 sm:py-8"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-16 h-72 w-72 rounded-full border-[28px] border-lime-300/15"
            />
            <div
                class="relative flex flex-col gap-7 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-2xl space-y-3">
                    <p
                        class="font-mono text-[11px] font-semibold tracking-[0.24em] text-lime-300 uppercase"
                    >
                        Resources / Catalog
                    </p>
                    <h1
                        class="max-w-xl text-3xl font-semibold tracking-tight md:text-4xl"
                    >
                        Prepare the pieces that make a timetable real.
                    </h1>
                    <p class="max-w-xl text-sm leading-6 text-slate-300">
                        Add the people, places, and courses that a scheduler can
                        actually place on the week.
                    </p>
                </div>
                <div
                    class="grid w-full max-w-full grid-cols-2 gap-2 text-center sm:grid-cols-4 lg:w-auto lg:min-w-[22rem]"
                >
                    <div
                        class="rounded-xl border border-white/10 bg-white/5 px-3 py-3"
                    >
                        <p class="text-2xl font-semibold">
                            {{ faculty.length }}
                        </p>
                        <p
                            class="font-mono text-[10px] tracking-wider text-slate-400 uppercase"
                        >
                            Faculty
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-white/10 bg-white/5 px-3 py-3"
                    >
                        <p class="text-2xl font-semibold">{{ rooms.length }}</p>
                        <p
                            class="font-mono text-[10px] tracking-wider text-slate-400 uppercase"
                        >
                            Rooms
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-white/10 bg-white/5 px-3 py-3"
                    >
                        <p class="text-2xl font-semibold">
                            {{ subjects.length }}
                        </p>
                        <p
                            class="font-mono text-[10px] tracking-wider text-slate-400 uppercase"
                        >
                            Subjects
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-white/10 bg-white/5 px-3 py-3"
                    >
                        <p class="text-2xl font-semibold">
                            {{ offerings.length }}
                        </p>
                        <p
                            class="font-mono text-[10px] tracking-wider text-slate-400 uppercase"
                        >
                            Offerings
                        </p>
                    </div>
                </div>
            </div>
        </header>

        <SetupRail :steps="resourceSetupSteps" />

        <section class="grid min-w-0 gap-4 md:grid-cols-3">
            <div
                class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
            >
                <Users class="mb-4 h-5 w-5 text-lime-600" />
                <p
                    class="font-mono text-[11px] tracking-wider text-muted-foreground uppercase"
                >
                    People and places
                </p>
                <p class="mt-1 text-lg font-semibold">
                    {{ resourceCount }} ready to schedule
                </p>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    Faculty and rooms can be assigned to classes and checked for
                    conflicts.
                </p>
            </div>
            <div
                class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
            >
                <Layers3 class="mb-4 h-5 w-5 text-amber-600" />
                <p
                    class="font-mono text-[11px] tracking-wider text-muted-foreground uppercase"
                >
                    Course building blocks
                </p>
                <p class="mt-1 text-lg font-semibold">
                    {{ subjects.length }} subjects ready
                </p>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    Add lecture or lab details once, then reuse them in each
                    period offering.
                </p>
            </div>
            <div
                class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
            >
                <Clock3 class="mb-4 h-5 w-5 text-sky-600" />
                <p
                    class="font-mono text-[11px] tracking-wider text-muted-foreground uppercase"
                >
                    When scheduling is allowed
                </p>
                <p class="mt-1 text-lg font-semibold">Add working hours</p>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    Tell the scheduler when a person or room is available,
                    unavailable, or reserved for a period.
                </p>
            </div>
        </section>

        <section
            id="resource-registry"
            v-if="canManageResources"
            class="scroll-mt-6 space-y-4"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <Heading
                    class="min-w-0"
                    variant="small"
                    title="Resource registry"
                    description="Add the people and places a timetable can reserve."
                />
                <Badge class="shrink-0" variant="outline"
                    >{{ faculty.length + rooms.length }} records</Badge
                >
            </div>

            <div class="grid min-w-0 gap-5 xl:grid-cols-2">
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
                    <div class="mb-5 flex items-start gap-3">
                        <div
                            class="rounded-lg bg-lime-500/15 p-2 text-lime-700 dark:text-lime-300"
                        >
                            <Users class="h-4 w-4" />
                        </div>
                        <div>
                            <h2 class="font-semibold">Add faculty</h2>
                            <p
                                class="mt-1 text-sm leading-5 text-muted-foreground"
                            >
                                Creates the faculty profile and paired
                                scheduling resource.
                            </p>
                        </div>
                    </div>
                    <Form
                        v-bind="storeFaculty.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <Label for="faculty-resource-name"
                                    >Resource name</Label
                                ><Input
                                    id="faculty-resource-name"
                                    name="resource_name"
                                    :class="fieldClass"
                                    placeholder="Dr. Ada Lovelace"
                                /><InputError :message="errors.resource_name" />
                            </div>
                            <div>
                                <Label for="employee-number"
                                    >Employee number</Label
                                ><Input
                                    id="employee-number"
                                    name="employee_number"
                                    :class="fieldClass"
                                    placeholder="FAC-001"
                                /><InputError
                                    :message="errors.employee_number"
                                />
                            </div>
                            <div>
                                <Label for="faculty-position">Position</Label
                                ><Input
                                    id="faculty-position"
                                    name="position"
                                    :class="fieldClass"
                                    placeholder="Associate professor"
                                /><InputError :message="errors.position" />
                            </div>
                            <div>
                                <Label for="employment-type"
                                    >Employment type</Label
                                ><select
                                    id="employment-type"
                                    name="employment_type"
                                    :class="selectClass"
                                >
                                    <option value="">Select type</option>
                                    <option
                                        v-for="option in employmentTypes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option></select
                                ><InputError
                                    :message="errors.employment_type"
                                />
                            </div>
                            <div>
                                <Label for="faculty-unit"
                                    >Primary academic unit</Label
                                ><select
                                    id="faculty-unit"
                                    name="academic_unit_id"
                                    :class="selectClass"
                                >
                                    <option value="">Unassigned</option>
                                    <option
                                        v-for="unit in units"
                                        :key="unit.id"
                                        :value="unit.id"
                                    >
                                        {{ unit.label }}
                                    </option></select
                                ><InputError
                                    :message="errors.academic_unit_id"
                                />
                            </div>
                            <div>
                                <Label for="daily-limit"
                                    >Daily limit (minutes)</Label
                                ><Input
                                    id="daily-limit"
                                    name="maximum_daily_minutes"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="480"
                                /><InputError
                                    :message="errors.maximum_daily_minutes"
                                />
                            </div>
                            <div>
                                <Label for="weekly-limit"
                                    >Weekly limit (minutes)</Label
                                ><Input
                                    id="weekly-limit"
                                    name="maximum_weekly_minutes"
                                    type="number"
                                    :class="fieldClass"
                                    placeholder="2400"
                                /><InputError
                                    :message="errors.maximum_weekly_minutes"
                                />
                            </div>
                        </div>
                        <Button
                            class="mt-5"
                            type="submit"
                            :disabled="processing"
                            ><Plus class="h-4 w-4" /> Add faculty</Button
                        >
                    </Form>
                </article>

                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
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
                                Rooms are schedulable resources with capacity
                                and delivery constraints.
                            </p>
                        </div>
                    </div>
                    <Form
                        v-bind="storeRoom.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <Label for="room-resource-name"
                                    >Resource name</Label
                                ><Input
                                    id="room-resource-name"
                                    name="resource_name"
                                    :class="fieldClass"
                                    placeholder="Science Lab 1"
                                /><InputError :message="errors.resource_name" />
                            </div>
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
                </article>
            </div>

            <div class="grid min-w-0 gap-5 lg:grid-cols-3">
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
                    <div class="mb-4 flex items-center gap-3">
                        <School class="h-4 w-4 text-sky-600" />
                        <h2 class="font-semibold">Room type</h2>
                    </div>
                    <Form
                        v-bind="storeRoomType.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
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
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
                    <div class="mb-4 flex items-center gap-3">
                        <FlaskConical class="h-4 w-4 text-amber-600" />
                        <h2 class="font-semibold">Room feature</h2>
                    </div>
                    <Form
                        v-bind="storeFeature.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
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
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
                    <div class="mb-4 flex items-center gap-3">
                        <Building2 class="h-4 w-4 text-lime-600" />
                        <h2 class="font-semibold">Building</h2>
                    </div>
                    <Form
                        v-bind="storeBuilding.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
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

            <div
                v-if="faculty.length > 0 || rooms.length > 0"
                class="grid min-w-0 gap-5 lg:grid-cols-2"
            >
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="font-semibold">Faculty ledger</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Primary units and load limits are visible before
                            scheduling.
                        </p>
                    </div>
                    <div class="divide-y divide-border">
                        <div
                            v-for="person in faculty"
                            :key="person.id"
                            class="flex min-w-0 items-start justify-between gap-4 px-5 py-4"
                        >
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ person.name }}
                                </p>
                                <p
                                    class="mt-1 text-xs break-words text-muted-foreground"
                                >
                                    {{
                                        person.employee_number ||
                                        'No employee number'
                                    }}
                                    <span v-if="person.position"
                                        >/ {{ person.position }}</span
                                    >
                                </p>
                                <p
                                    class="mt-1 text-xs break-words text-muted-foreground"
                                >
                                    {{
                                        person.units
                                            .map((unit) => unit.name)
                                            .join(', ') || 'Unassigned'
                                    }}
                                </p>
                            </div>
                            <span
                                class="font-mono text-xs leading-5 text-muted-foreground"
                                >{{
                                    formatMinutes(
                                        person.maximum_weekly_minutes,
                                    )
                                }}/wk</span
                            >
                        </div>
                        <p
                            v-if="faculty.length === 0"
                            class="px-5 py-6 text-sm leading-5 text-muted-foreground"
                        >
                            No faculty profiles yet.
                        </p>
                    </div>
                </article>
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="font-semibold">Room ledger</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Capacity and delivery mode follow the room resource.
                        </p>
                    </div>
                    <div class="divide-y divide-border">
                        <div
                            v-for="room in rooms"
                            :key="room.id"
                            class="flex min-w-0 items-start justify-between gap-4 px-5 py-4"
                        >
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ room.code }} / {{ room.name }}
                                </p>
                                <p
                                    class="mt-1 text-xs break-words text-muted-foreground"
                                >
                                    {{ room.building_code || 'No building' }} /
                                    {{ room.room_type_code }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 font-mono text-xs leading-5 text-muted-foreground"
                                >{{ room.capacity || '—' }} seats</span
                            >
                        </div>
                        <p
                            v-if="rooms.length === 0"
                            class="px-5 py-6 text-sm leading-5 text-muted-foreground"
                        >
                            No rooms yet.
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <section
            id="catalog"
            v-if="canManageCatalog"
            class="scroll-mt-6 space-y-4"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <Heading
                    class="min-w-0"
                    variant="small"
                    title="Catalog and offerings"
                    description="Build reusable subjects, then turn them into period offerings."
                /><Badge class="shrink-0" variant="outline"
                    >{{ offerings.length }} recent offerings</Badge
                >
            </div>
            <div class="grid min-w-0 gap-5 xl:grid-cols-2">
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
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
                        v-bind="storeSubject.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
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
                </article>
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
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
                                Define lecture/lab requirements; offering
                                creation snapshots them.
                            </p>
                        </div>
                    </div>
                    <Form
                        v-bind="storeComponent.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <Label for="component-subject">Subject</Label
                                ><select
                                    id="component-subject"
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
                                <Label for="duration">Duration / session</Label
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
                </article>
            </div>
            <div
                class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]"
            >
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
                >
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
                        v-bind="storeOffering.form([organizationSlug])"
                        #default="{ errors, processing }"
                    >
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
                </article>
                <article
                    class="min-w-0 rounded-2xl border border-border/70 bg-card"
                >
                    <div class="border-b border-border/70 px-5 py-4">
                        <h2 class="font-semibold">Subject catalog</h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            Components currently attached to each subject.
                        </p>
                    </div>
                    <div class="divide-y divide-border">
                        <div
                            v-for="subject in subjects"
                            :key="subject.id"
                            class="min-w-0 px-5 py-4"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="font-medium break-words">
                                        {{ subject.code }} / {{ subject.name }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        {{ subject.units || '—' }} units /
                                        {{ subject.components.length }}
                                        component(s)
                                    </p>
                                </div>
                                <Badge class="shrink-0" variant="outline"
                                    >Catalog</Badge
                                >
                            </div>
                            <div
                                v-if="subject.components.length"
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="component in subject.components"
                                    :key="component.kind + component.name"
                                    class="rounded-md bg-muted px-2 py-1 font-mono text-[11px]"
                                    >{{ component.kind }} ·
                                    {{ component.weekly_minutes }}m</span
                                >
                            </div>
                        </div>
                        <p
                            v-if="subjects.length === 0"
                            class="px-5 py-6 text-sm leading-5 text-muted-foreground"
                        >
                            No subjects yet.
                        </p>
                    </div>
                </article>
            </div>
            <article
                class="min-w-0 rounded-2xl border border-border/70 bg-card"
            >
                <div class="border-b border-border/70 px-5 py-4">
                    <h2 class="font-semibold">Recent offerings</h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        The component count confirms that subject defaults were
                        copied into the period offering.
                    </p>
                </div>
                <div
                    class="grid min-w-0 gap-3 p-5 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="offering in offerings"
                        :key="offering.id"
                        class="min-w-0 rounded-xl border border-border/70 p-4"
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
                    </div>
                    <p
                        v-if="offerings.length === 0"
                        class="text-sm leading-5 text-muted-foreground"
                    >
                        No offerings yet.
                    </p>
                </div>
            </article>
        </section>

        <section
            id="availability"
            v-if="canManageResources"
            class="scroll-mt-6 space-y-4"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <Heading
                    class="min-w-0"
                    variant="small"
                    title="Availability rules"
                    description="Apply global or period-specific windows to any active faculty or room resource."
                /><Badge class="shrink-0" variant="outline"
                    >{{ resources.length }} selectable resources</Badge
                >
            </div>
            <article
                class="min-w-0 rounded-2xl border border-border/70 bg-card p-5"
            >
                <div
                    class="mb-5 flex items-start gap-3 rounded-xl bg-muted/50 px-4 py-3"
                >
                    <Clock3 class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
                    <p class="text-xs leading-5 text-muted-foreground">
                        Set a window using clock minutes:
                        <span class="font-mono text-foreground">480</span> is
                        8:00 AM and
                        <span class="font-mono text-foreground">1020</span> is
                        5:00 PM. Use a period override only when it differs from
                        the global rule.
                    </p>
                </div>
                <Form
                    v-bind="storeAvailability.form([organizationSlug])"
                    #default="{ errors, processing }"
                >
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="xl:col-span-2">
                            <Label for="availability-resource">Resource</Label
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
                                    {{ period.year_name }} / {{ period.name }}
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
                            <Label for="starts-at">Starts at (minutes)</Label
                            ><Input
                                id="starts-at"
                                name="starts_at_minute"
                                type="number"
                                min="0"
                                max="1439"
                                :class="fieldClass"
                                placeholder="480"
                            /><InputError :message="errors.starts_at_minute" />
                        </div>
                        <div>
                            <Label for="ends-at">Ends at (minutes)</Label
                            ><Input
                                id="ends-at"
                                name="ends_at_minute"
                                type="number"
                                min="1"
                                max="1440"
                                :class="fieldClass"
                                placeholder="1020"
                            /><InputError :message="errors.ends_at_minute" />
                        </div>
                        <div>
                            <Label for="effective-from">Effective from</Label
                            ><Input
                                id="effective-from"
                                name="effective_from"
                                type="date"
                                :class="fieldClass"
                            /><InputError :message="errors.effective_from" />
                        </div>
                        <div>
                            <Label for="effective-until">Effective until</Label
                            ><Input
                                id="effective-until"
                                name="effective_until"
                                type="date"
                                :class="fieldClass"
                            /><InputError :message="errors.effective_until" />
                        </div>
                        <div>
                            <Label for="priority">Priority</Label
                            ><Input
                                id="priority"
                                name="priority"
                                type="number"
                                min="0"
                                :class="fieldClass"
                                value="0"
                            /><InputError :message="errors.priority" />
                            <p
                                class="mt-1 text-[11px] leading-4 text-muted-foreground"
                            >
                                Use 0 for the normal rule; higher values take
                                precedence.
                            </p>
                        </div>
                    </div>
                    <Button class="mt-5" type="submit" :disabled="processing"
                        ><Plus class="h-4 w-4" /> Add availability rule</Button
                    >
                </Form>
            </article>
        </section>
    </div>
</template>
