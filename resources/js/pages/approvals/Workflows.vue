<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    GitBranch,
    Plus,
    Power,
    Search,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ApprovalNavigation from '@/components/ApprovalNavigation.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { inbox, workflows as workflowsRoute } from '@/routes/approvals';
import { store as storeWorkflow, retire } from '@/routes/approvals/workflows';
import { activate } from '@/routes/approvals/workflows/versions';
import type { Organization } from '@/types';

type DraftStep = { id: string; selector: 'permission' | 'role' };
type WorkflowStep = {
    sequence: number;
    label: string;
    selector_type: string;
    required_permission: string | null;
    role_codes: string[];
    minimum_approvals: number;
    allow_self_approval: boolean;
    signatory_slot: string | null;
    academic_unit_id: string | null;
    academic_unit_name: string | null;
};
type WorkflowVersion = {
    version: number;
    activated_at: string | null;
    is_active: boolean;
    require_distinct_approvers: boolean;
    steps: WorkflowStep[];
};
type Workflow = {
    id: string;
    name: string;
    is_active: boolean;
    versions: WorkflowVersion[];
};
type Props = {
    workflows: Workflow[];
    units: { id: string; name: string; code: string | null }[];
    roles: { code: string; name: string }[];
    permissions: { value: string; label: string }[];
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const section = ref<'library' | 'draft'>('library');
const search = ref('');
const newStep = (): DraftStep => ({
    id: crypto.randomUUID(),
    selector: 'permission',
});
const drafts = ref<DraftStep[]>([newStep()]);
const visibleWorkflows = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.workflows.filter((workflow) =>
        workflow.name.toLowerCase().includes(query),
    );
});
const saved = (): void => {
    drafts.value = [newStep()];
    section.value = 'library';
    search.value = '';
};
const addStep = (): void => {
    if (drafts.value.length < 12) {
        drafts.value.push(newStep());
    }
};
const removeStep = (index: number): void => {
    if (drafts.value.length > 1) {
        drafts.value.splice(index, 1);
    }
};
const statusLabel = (workflow: Workflow): string =>
    workflow.is_active
        ? 'Active'
        : workflow.versions.some((version) => version.is_active)
          ? 'Retired'
          : 'Draft';
const selectorLabel = (step: WorkflowStep): string =>
    step.selector_type === 'permission'
        ? (props.permissions.find(
              (permission) => permission.value === step.required_permission,
          )?.label ??
          step.required_permission ??
          'Permission')
        : step.role_codes
              .map(
                  (code) =>
                      props.roles.find((role) => role.code === code)?.name ??
                      code,
              )
              .join(', ');

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Approvals',
                href: inbox(layoutProps.currentOrganization?.slug ?? '').url,
            },
            {
                title: 'Workflow designer',
                href: workflowsRoute(
                    layoutProps.currentOrganization?.slug ?? '',
                ).url,
            },
        ],
    }),
});
</script>

<template>
    <div class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 sm:p-6">
        <Head title="Approval workflows" />
        <WorkspacePageHeader
            section="Review & publish"
            title="Workflow designer"
            description="Set the review steps a timetable follows before it is ready to publish."
        >
            <template #actions>
                <Button @click="section = 'draft'"
                    ><Plus /> New workflow</Button
                >
            </template>
        </WorkspacePageHeader>
        <ApprovalNavigation
            :organization-slug="organization?.slug ?? ''"
            current="workflows"
            :can-manage="true"
        />
        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Workflow sections"
        >
            <Button
                :variant="section === 'library' ? 'secondary' : 'ghost'"
                :aria-pressed="section === 'library'"
                @click="section = 'library'"
                ><GitBranch /> Saved workflows
                <span class="font-schedule text-xs">{{
                    workflows.length
                }}</span></Button
            >
            <Button
                :variant="section === 'draft' ? 'secondary' : 'ghost'"
                :aria-pressed="section === 'draft'"
                @click="section = 'draft'"
                ><Plus /> Draft workflow</Button
            >
        </div>

        <section
            v-show="section === 'draft'"
            id="new-workflow"
            class="min-w-0 rounded-lg border bg-card p-5 sm:p-6"
        >
            <div class="flex items-start gap-3">
                <div
                    class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/30 bg-schedule/10 text-schedule"
                >
                    <ShieldCheck class="size-5" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold">
                        Draft a workflow version
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        Arrange reviewers in the order they should approve. Save
                        a draft, then activate it for new submissions.
                    </p>
                </div>
            </div>
            <Form
                v-bind="storeWorkflow.form(organization?.slug ?? '')"
                class="mt-6 space-y-6"
                v-slot="{ errors, processing }"
                reset-on-success
                @success="saved"
            >
                <ValidationSummary
                    :errors="errors"
                    title="Check the workflow details"
                    :field-ids="{ name: 'workflow-name' }"
                />
                <div class="grid gap-2 sm:max-w-xl">
                    <Label for="workflow-name">Workflow name</Label
                    ><Input
                        id="workflow-name"
                        name="name"
                        required
                        maxlength="160"
                        placeholder="e.g. Academic timetable review"
                    />
                    <p class="text-xs leading-5 text-muted-foreground">
                        Using an existing workflow name creates its next
                        version.
                    </p>
                    <p v-if="errors.name" class="text-sm text-destructive">
                        {{ errors.name }}
                    </p>
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input
                        type="checkbox"
                        name="require_distinct_approvers"
                        value="1"
                        class="size-4 rounded border-input accent-primary"
                    />
                    Require a different approver at every step</label
                >
                <p v-if="errors.steps" class="text-sm text-destructive">
                    {{ errors.steps }}
                </p>
                <div class="space-y-4">
                    <div
                        v-for="(draft, index) in drafts"
                        :key="draft.id"
                        class="relative rounded-md border border-l-2 border-l-schedule/40 bg-muted/20 p-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <p
                                class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase"
                            >
                                Step {{ index + 1 }}
                            </p>
                            <Button
                                v-if="drafts.length > 1"
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Remove step ${index + 1}`"
                                @click="removeStep(index)"
                                ><Trash2 /> Remove</Button
                            >
                        </div>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div class="grid gap-2">
                                <Label :for="'step-label-' + index"
                                    >Step label</Label
                                ><Input
                                    :id="'step-label-' + index"
                                    :name="'steps[' + index + '][label]'"
                                    required
                                    placeholder="Scheduling review"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'step-unit-' + index"
                                    >Academic unit scope</Label
                                ><select
                                    :id="'step-unit-' + index"
                                    :name="
                                        'steps[' + index + '][academic_unit_id]'
                                    "
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="">Organization-wide</option>
                                    <option
                                        v-for="unit in props.units"
                                        :key="unit.id"
                                        :value="unit.id"
                                    >
                                        {{ unit.name
                                        }}{{
                                            unit.code ? ' · ' + unit.code : ''
                                        }}
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="'step-selector-' + index"
                                    >Approver selector</Label
                                ><select
                                    v-model="draft.selector"
                                    :id="'step-selector-' + index"
                                    :name="
                                        'steps[' +
                                        index +
                                        '][approver_selector_type]'
                                    "
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option value="permission">
                                        Permission
                                    </option>
                                    <option value="role">
                                        Organization role
                                    </option>
                                </select>
                            </div>
                            <div
                                v-if="draft.selector === 'permission'"
                                class="grid gap-2"
                            >
                                <Label :for="'step-permission-' + index"
                                    >Required permission</Label
                                ><select
                                    :id="'step-permission-' + index"
                                    :name="
                                        'steps[' +
                                        index +
                                        '][required_permission]'
                                    "
                                    class="h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option
                                        v-for="permission in props.permissions"
                                        :key="permission.value"
                                        :value="permission.value"
                                    >
                                        {{ permission.label }}
                                    </option>
                                </select>
                            </div>
                            <div v-else class="grid gap-2">
                                <Label :for="'step-role-' + index"
                                    >Organization roles</Label
                                ><select
                                    :id="'step-role-' + index"
                                    multiple
                                    :name="'steps[' + index + '][role_codes][]'"
                                    class="min-h-24 w-full min-w-0 rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <option
                                        v-for="role in props.roles"
                                        :key="role.code"
                                        :value="role.code"
                                    >
                                        {{ role.name }}
                                    </option>
                                </select>
                            </div>
                            <div
                                class="grid gap-2 sm:grid-cols-3 lg:col-span-2"
                            >
                                <div class="grid gap-2">
                                    <Label :for="'step-minimum-' + index"
                                        >Minimum approvals</Label
                                    ><Input
                                        :id="'step-minimum-' + index"
                                        :name="
                                            'steps[' +
                                            index +
                                            '][minimum_approvals]'
                                        "
                                        type="number"
                                        min="1"
                                        max="20"
                                        :default-value="1"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label :for="'step-slot-' + index"
                                        >Signatory slot</Label
                                    ><Input
                                        :id="'step-slot-' + index"
                                        :name="
                                            'steps[' +
                                            index +
                                            '][signatory_slot]'
                                        "
                                        placeholder="registrar"
                                    />
                                </div>
                                <label
                                    class="flex items-center gap-2 self-end pb-2 text-sm"
                                    ><input
                                        type="checkbox"
                                        :name="
                                            'steps[' +
                                            index +
                                            '][allow_self_approval]'
                                        "
                                        value="1"
                                        class="size-4 rounded border-input accent-primary"
                                    />
                                    Allow submitter</label
                                >
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap justify-between gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="drafts.length >= 12"
                        @click="addStep"
                        ><Plus /> Add step</Button
                    ><span class="self-center text-xs text-muted-foreground"
                        >{{ drafts.length }} of 12 steps</span
                    ><Button type="submit" :disabled="processing"
                        ><Check />
                        {{
                            processing ? 'Saving...' : 'Save draft version'
                        }}</Button
                    >
                </div>
            </Form>
        </section>

        <section
            v-show="section === 'library'"
            class="min-w-0 space-y-4"
            aria-label="Saved workflows"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2 class="text-base font-semibold">Review paths</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Active workflows are available when a timetable is
                        submitted.
                    </p>
                </div>
                <div
                    v-if="workflows.length"
                    class="relative w-full sm:max-w-64"
                >
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    /><Input
                        v-model="search"
                        type="search"
                        aria-label="Search workflows"
                        placeholder="Find a workflow..."
                        class="pl-9"
                    />
                </div>
            </div>
            <WorkspaceState
                v-if="workflows.length === 0"
                variant="empty"
                title="No workflows yet"
                description="Create a review path with the people and permissions needed to approve a timetable."
                ><template #action
                    ><Button @click="section = 'draft'"
                        ><Plus /> Create a workflow</Button
                    ></template
                ></WorkspaceState
            >
            <WorkspaceState
                v-else-if="visibleWorkflows.length === 0"
                variant="empty"
                title="No matching workflows"
                description="Try another workflow name."
                ><template #action
                    ><Button variant="outline" @click="search = ''"
                        >Clear search</Button
                    ></template
                ></WorkspaceState
            >
            <div v-else class="space-y-4">
                <section
                    v-for="workflow in visibleWorkflows"
                    :key="workflow.id"
                    class="min-w-0 overflow-hidden rounded-lg border bg-card"
                    :aria-label="workflow.name"
                >
                    <header
                        class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/25 px-4 py-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="grid size-9 shrink-0 place-items-center rounded-md border border-schedule/25 bg-schedule/10 text-schedule"
                            >
                                <GitBranch class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold break-words">
                                    {{ workflow.name }}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ workflow.versions.length }} saved
                                    {{
                                        workflow.versions.length === 1
                                            ? 'version'
                                            : 'versions'
                                    }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            variant="outline"
                            :class="
                                workflow.is_active
                                    ? 'border-available/30 bg-available/10 text-available'
                                    : 'border-border bg-muted text-muted-foreground'
                            "
                            >{{ statusLabel(workflow) }}</Badge
                        >
                    </header>
                    <div class="divide-y">
                        <details
                            v-for="(version, index) in workflow.versions"
                            :key="version.version"
                            :open="index === 0"
                            class="group"
                        >
                            <summary
                                class="flex min-h-12 cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                            >
                                <span class="flex flex-wrap items-center gap-2"
                                    ><span class="font-schedule text-xs"
                                        >VERSION {{ version.version }}</span
                                    ><Badge
                                        variant="outline"
                                        :class="
                                            version.is_active
                                                ? 'border-available/30 bg-available/10 text-available'
                                                : 'border-warning/30 bg-warning/10 text-foreground'
                                        "
                                        >{{
                                            version.is_active
                                                ? 'Activated'
                                                : 'Draft'
                                        }}</Badge
                                    ></span
                                >
                                <span
                                    class="flex items-center gap-3 text-xs text-muted-foreground"
                                    >{{ version.steps.length }}
                                    {{
                                        version.steps.length === 1
                                            ? 'step'
                                            : 'steps'
                                    }}<ChevronDown
                                        class="size-4 transition-transform group-open:rotate-180 motion-reduce:transition-none"
                                /></span>
                            </summary>
                            <div class="space-y-4 px-4 pb-4 sm:px-5">
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        version.require_distinct_approvers
                                            ? 'A different approver is required at every step.'
                                            : 'An eligible approver can take part in more than one step.'
                                    }}
                                </p>
                                <ol class="space-y-0">
                                    <li
                                        v-for="(
                                            step, stepIndex
                                        ) in version.steps"
                                        :key="step.sequence"
                                        class="relative flex gap-3 pb-5 last:pb-0"
                                    >
                                        <span
                                            v-if="
                                                stepIndex <
                                                version.steps.length - 1
                                            "
                                            aria-hidden="true"
                                            class="absolute top-7 left-3.5 h-[calc(100%-1.75rem)] w-px bg-border"
                                        />
                                        <span
                                            class="relative grid size-7 shrink-0 place-items-center rounded-full border bg-muted/50 font-schedule text-xs"
                                            >{{ step.sequence }}</span
                                        >
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="text-sm font-medium break-words"
                                            >
                                                {{ step.label }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs leading-5 text-muted-foreground"
                                            >
                                                {{ selectorLabel(step) }} ·
                                                {{
                                                    step.academic_unit_name ??
                                                    'Organization-wide'
                                                }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                {{ step.minimum_approvals }}
                                                {{
                                                    step.minimum_approvals === 1
                                                        ? 'approval'
                                                        : 'approvals'
                                                }}
                                                required<span
                                                    v-if="step.signatory_slot"
                                                >
                                                    · Signature:
                                                    {{
                                                        step.signatory_slot
                                                    }}</span
                                                >
                                            </p>
                                        </div>
                                    </li>
                                </ol>
                                <Form
                                    v-if="!version.is_active"
                                    v-bind="
                                        activate.form([
                                            organization?.slug ?? '',
                                            workflow.id,
                                            version.version,
                                        ])
                                    "
                                    v-slot="{ errors, processing }"
                                    class="space-y-3 border-t pt-4"
                                >
                                    <ValidationSummary
                                        :errors="errors"
                                        title="This version could not be activated"
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="processing"
                                        ><Power />
                                        {{
                                            processing
                                                ? 'Activating...'
                                                : 'Activate version'
                                        }}</Button
                                    >
                                </Form>
                            </div>
                        </details>
                    </div>
                    <Form
                        v-if="workflow.is_active"
                        v-bind="
                            retire.form([organization?.slug ?? '', workflow.id])
                        "
                        class="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/15 px-4 py-3"
                        v-slot="{ errors, processing }"
                    >
                        <ValidationSummary
                            :errors="errors"
                            title="This workflow could not be retired"
                            class="w-full"
                        />
                        <p class="text-xs text-muted-foreground">
                            Retiring stops new submissions. Existing reviews
                            keep their steps.
                        </p>
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                            ><Power /> Retire workflow</Button
                        >
                    </Form>
                </section>
            </div>
        </section>
    </div>
</template>
