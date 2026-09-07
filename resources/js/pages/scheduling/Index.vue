<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { CalendarDays, Plus, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import TimetableDirectory from '@/components/TimetableDirectory.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { setup as academicSetup } from '@/routes/academic';
import { index, store } from '@/routes/scheduling/timetables';
import { show as subscriptionShow } from '@/routes/subscriptions';
import type { Organization } from '@/types';
import type { TimetableSummary } from '@/types/timetable-directory';

const props = defineProps<{
    timetables: {
        data: TimetableSummary[];
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    periods: { id: string; name: string; year: string }[];
    search: string;
    canCreateTimetable: boolean;
    capacity: {
        current: number | null;
        limit: number | null;
        available: boolean;
    };
}>();
defineOptions({
    layout: (props: { currentOrganization: Organization }) => ({
        breadcrumbs: [
            {
                title: 'Timetables',
                href: index(props.currentOrganization.slug),
            },
        ],
    }),
});
const page = usePage();
const slug = computed(() => page.props.currentOrganization!.slug);
const search = ref(props.search);
const searching = ref(false);
const creating = ref(false);
function findTimetables() {
    router.get(
        index(slug.value).url,
        { search: search.value },
        {
            preserveState: true,
            replace: true,
            onStart: () => {
                searching.value = true;
            },
            onFinish: () => {
                searching.value = false;
            },
        },
    );
}
const canStart = computed(
    () =>
        props.canCreateTimetable &&
        props.capacity.available &&
        props.periods.length > 0,
);
</script>

<template>
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 sm:p-6">
        <Head title="Timetables" />
        <WorkspacePageHeader
            title="Timetables"
            section="Scheduling"
            description="One timetable per academic period. Open a timetable to schedule classes, manage versions, and publish."
        >
            <template #actions>
                <Dialog v-if="canStart" v-model:open="creating">
                    <DialogTrigger as-child
                        ><Button class="min-h-10"
                            ><Plus class="size-4" /> Create timetable</Button
                        ></DialogTrigger
                    >
                    <DialogContent>
                        <DialogHeader
                            ><DialogTitle>Create timetable</DialogTitle
                            ><DialogDescription
                                >Choose an academic period. We will create an
                                empty first draft using your organization's
                                timezone and time slots.</DialogDescription
                            ></DialogHeader
                        >
                        <Form
                            v-bind="store.form(slug)"
                            #default="{ errors, processing }"
                            class="space-y-5"
                        >
                            <ValidationSummary
                                :errors="errors"
                                :field-labels="{
                                    academic_period_id: 'Academic period',
                                    name: 'Timetable name',
                                }"
                                :field-ids="{
                                    academic_period_id: 'timetable-period',
                                    name: 'timetable-name',
                                }"
                            />
                            <div class="space-y-2">
                                <Label for="timetable-name"
                                    >Timetable name</Label
                                ><Input
                                    id="timetable-name"
                                    name="name"
                                    placeholder="e.g. First quarter schedule"
                                    required
                                    maxlength="255"
                                    class="h-11"
                                    :aria-invalid="Boolean(errors.name)"
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="timetable-period"
                                    >Academic period</Label
                                >
                                <select
                                    id="timetable-period"
                                    name="academic_period_id"
                                    required
                                    class="h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-invalid="
                                        Boolean(errors.academic_period_id)
                                    "
                                >
                                    <option value="">Choose a period</option>
                                    <option
                                        v-for="period in periods"
                                        :key="period.id"
                                        :value="period.id"
                                    >
                                        {{ period.name }} · {{ period.year }}
                                    </option>
                                </select>
                                <p
                                    class="text-xs leading-5 text-muted-foreground"
                                >
                                    Only periods in open academic years without
                                    a timetable are listed.
                                </p>
                            </div>
                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="processing"
                                    @click="creating = false"
                                    >Cancel</Button
                                ><Button type="submit" :disabled="processing">{{
                                    processing
                                        ? 'Creating...'
                                        : 'Create timetable'
                                }}</Button>
                            </div>
                        </Form>
                    </DialogContent>
                </Dialog>
            </template>
        </WorkspacePageHeader>

        <div
            v-if="canCreateTimetable && !capacity.available"
            class="rounded-lg border border-warning/30 bg-warning/5 p-4 text-sm"
            role="status"
        >
            <p class="font-medium">
                {{
                    capacity.limit === null
                        ? 'Timetable creation is not included in the current plan settings.'
                        : 'Your timetable limit has been reached.'
                }}
            </p>
            <p class="mt-1 text-muted-foreground">
                Existing timetables are still available. Contact your
                organization administrator to review capacity.
            </p>
            <Link
                v-if="page.props.canManageSubscription"
                :href="subscriptionShow(slug)"
                class="mt-2 inline-flex min-h-10 items-center font-medium text-primary underline underline-offset-4"
                >Review plan & usage</Link
            >
        </div>
        <div
            v-else-if="canCreateTimetable && periods.length === 0"
            class="flex flex-col justify-between gap-3 rounded-lg border bg-muted/30 p-4 text-sm sm:flex-row sm:items-center"
        >
            <p>
                Add an academic period to start a new timetable. Periods that
                already have a timetable appear in the list.
            </p>
            <Button
                v-if="page.props.workspacePermissions.academic"
                as-child
                variant="outline"
                class="min-h-10 shrink-0"
                ><Link :href="academicSetup(slug)"
                    >Open academic setup</Link
                ></Button
            >
        </div>
        <p
            v-else-if="!canCreateTimetable"
            class="text-sm text-muted-foreground"
        >
            You can browse existing timetables. Creating one requires scheduling
            permission and an enabled scheduling capability.
        </p>

        <form
            class="flex flex-wrap items-center gap-2"
            role="search"
            @submit.prevent="findTimetables"
        >
            <div class="relative min-w-0 flex-1 sm:max-w-sm">
                <Search
                    aria-hidden="true"
                    class="absolute top-3 left-3 size-4 text-muted-foreground"
                /><Input
                    v-model="search"
                    type="search"
                    aria-label="Search timetables"
                    placeholder="Search timetable names..."
                    maxlength="120"
                    class="h-10 pl-9"
                />
            </div>
            <Button
                type="submit"
                variant="outline"
                class="h-10"
                :disabled="searching"
                >{{ searching ? 'Searching...' : 'Search' }}</Button
            >
            <span class="ml-auto text-sm text-muted-foreground" role="status"
                >{{ timetables.total }}
                {{ timetables.total === 1 ? 'timetable' : 'timetables' }}</span
            >
        </form>
        <TimetableDirectory
            v-if="timetables.data.length"
            :timetables="timetables.data"
            :organization-slug="slug"
        />
        <WorkspaceState
            v-else
            variant="empty"
            :title="
                props.search ? 'No matching timetables' : 'No timetables yet'
            "
            :description="
                props.search
                    ? 'Try another name or clear your search to see all timetables.'
                    : 'Create your first timetable to start placing classes in the week.'
            "
        >
            <template #action>
                <Button
                    v-if="props.search"
                    variant="outline"
                    @click="
                        search = '';
                        findTimetables();
                    "
                    >Clear search</Button
                >
                <Button v-else-if="canStart" @click="creating = true"
                    ><CalendarDays class="size-4" /> Create your first
                    timetable</Button
                >
            </template>
        </WorkspaceState>
        <nav
            v-if="timetables.prev_page_url || timetables.next_page_url"
            aria-label="Timetable pages"
            class="flex items-center justify-between gap-3"
        >
            <p class="text-sm text-muted-foreground">
                {{ timetables.from }}–{{ timetables.to }} of
                {{ timetables.total }}
            </p>
            <div class="flex gap-2">
                <Button
                    v-if="timetables.prev_page_url"
                    as-child
                    variant="outline"
                    ><Link :href="timetables.prev_page_url"
                        >Previous</Link
                    ></Button
                ><Button
                    v-if="timetables.next_page_url"
                    as-child
                    variant="outline"
                    ><Link :href="timetables.next_page_url">Next</Link></Button
                >
            </div>
        </nav>
    </div>
</template>
