<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Check, Plus, Power, ShieldCheck, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { inbox, workflows as workflowsRoute } from '@/routes/approvals';
import { store as storeWorkflow, retire } from '@/routes/approvals/workflows';
import { activate } from '@/routes/approvals/workflows/versions';
import type { Organization } from '@/types';

type DraftStep = { selector: 'permission' | 'role' };
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
type Workflow = { id: string; name: string; is_active: boolean; versions: WorkflowVersion[] };
type Props = {
    workflows: Workflow[];
    units: { id: string; name: string; code: string | null }[];
    roles: { code: string; name: string }[];
    permissions: { value: string; label: string }[];
};

const props = defineProps<Props>();
const page = usePage();
const organization = computed(() => page.props.currentOrganization as Organization | null);
const drafts = ref<DraftStep[]>([{ selector: 'permission' }]);
const addStep = (): void => {
    drafts.value.push({ selector: 'permission' });
};
const removeStep = (index: number): void => {
    if (drafts.value.length > 1) {
        drafts.value.splice(index, 1);
    }
};
const statusLabel = (active: boolean): string => active ? 'Active' : 'Draft';
const selectorLabel = (step: WorkflowStep): string =>
    step.selector_type === 'permission' ? step.required_permission?.replaceAll(':', ' · ') ?? 'Permission' : step.role_codes.join(', ');

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            { title: 'Approvals', href: inbox(layoutProps.currentOrganization?.slug ?? '').url },
            { title: 'Workflow designer', href: workflowsRoute(layoutProps.currentOrganization?.slug ?? '').url },
        ],
    }),
});
</script>

<template>
    <Head title="Approval workflows" />
    <div class="space-y-8">
        <WorkspacePageHeader section="Approvals" title="Workflow designer" description="Create immutable workflow versions, inspect their selectors, and activate the version future submissions will use.">
            <template #actions><Button variant="outline" as-child><a href="#new-workflow">New workflow</a></Button></template>
        </WorkspacePageHeader>

        <section id="new-workflow" class="rounded-lg border bg-card p-5 sm:p-7">
            <div class="flex items-start gap-3"><div class="grid size-10 shrink-0 place-items-center rounded-md border border-schedule/30 bg-schedule/10 text-schedule"><ShieldCheck class="size-5" /></div><div><h2 class="text-lg font-semibold">Draft a workflow version</h2><p class="mt-1 text-sm leading-6 text-muted-foreground">Each save creates a new immutable version. Activate it only after all selectors and steps are ready.</p></div></div>
            <Form v-bind="storeWorkflow.form(organization?.slug ?? '')" class="mt-6 space-y-6" v-slot="{ errors, processing }">
                <div class="grid gap-2 sm:max-w-xl"><Label for="workflow-name">Workflow name</Label><Input id="workflow-name" name="name" required placeholder="e.g. Academic timetable review" /><p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="require_distinct_approvers" value="1" class="size-4 rounded border-input accent-primary" /> Require a different approver at every step</label>
                <p v-if="errors.steps" class="text-sm text-destructive">{{ errors.steps }}</p>
                <div class="space-y-4">
                    <div v-for="(draft, index) in drafts" :key="index" class="rounded-md border bg-muted/20 p-4">
                        <div class="flex items-center justify-between gap-3"><p class="font-schedule text-xs font-semibold tracking-[0.16em] text-muted-foreground uppercase">Step {{ index + 1 }}</p><Button v-if="drafts.length > 1" type="button" variant="ghost" size="sm" @click="removeStep(index)"><Trash2 /> Remove</Button></div>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <div class="grid gap-2"><Label :for="'step-label-' + index">Step label</Label><Input :id="'step-label-' + index" :name="'steps[' + index + '][label]'" required placeholder="Scheduling review" /></div>
                            <div class="grid gap-2"><Label :for="'step-unit-' + index">Academic unit scope</Label><select :id="'step-unit-' + index" :name="'steps[' + index + '][academic_unit_id]'" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option value="">Organization-wide</option><option v-for="unit in props.units" :key="unit.id" :value="unit.id">{{ unit.name }}{{ unit.code ? ' · ' + unit.code : '' }}</option></select></div>
                            <div class="grid gap-2"><Label :for="'step-selector-' + index">Approver selector</Label><select v-model="draft.selector" :id="'step-selector-' + index" :name="'steps[' + index + '][approver_selector_type]'" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option value="permission">Permission</option><option value="role">Organization role</option></select></div>
                            <div v-if="draft.selector === 'permission'" class="grid gap-2"><Label :for="'step-permission-' + index">Required permission</Label><select :id="'step-permission-' + index" :name="'steps[' + index + '][required_permission]'" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"><option v-for="permission in props.permissions" :key="permission.value" :value="permission.value">{{ permission.label }}</option></select></div>
                            <div v-else class="grid gap-2"><Label :for="'step-role-' + index">Organization roles</Label><select :id="'step-role-' + index" multiple :name="'steps[' + index + '][role_codes][]'" class="min-h-20 rounded-md border border-input bg-transparent px-3 py-2 text-sm"><option v-for="role in props.roles" :key="role.code" :value="role.code">{{ role.name }}</option></select></div>
                            <div class="grid gap-2 sm:grid-cols-3 lg:col-span-2">
                                <div class="grid gap-2"><Label :for="'step-minimum-' + index">Minimum approvals</Label><Input :id="'step-minimum-' + index" :name="'steps[' + index + '][minimum_approvals]'" type="number" min="1" value="1" /></div>
                                <div class="grid gap-2"><Label :for="'step-slot-' + index">Signatory slot</Label><Input :id="'step-slot-' + index" :name="'steps[' + index + '][signatory_slot]'" placeholder="registrar" /></div>
                                <label class="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" :name="'steps[' + index + '][allow_self_approval]'" value="1" class="size-4 rounded border-input accent-primary" /> Allow submitter</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap justify-between gap-3"><Button type="button" variant="outline" @click="addStep"><Plus /> Add step</Button><Button type="submit" :disabled="processing"><Check /> Save draft version</Button></div>
            </Form>
        </section>

        <WorkspaceState v-if="props.workflows.length === 0" variant="empty" title="No workflows yet" description="Create your first workflow version above to make timetable approvals available." />
        <div v-else class="grid gap-5 lg:grid-cols-2">
            <section v-for="workflow in props.workflows" :key="workflow.id" class="rounded-lg border bg-card p-5">
                <div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">{{ workflow.name }}</h2><p class="mt-1 text-sm text-muted-foreground">{{ workflow.versions.length }} immutable version{{ workflow.versions.length === 1 ? '' : 's' }}</p></div><Badge variant="outline" :class="workflow.is_active ? 'border-schedule/30 bg-schedule/10 text-schedule' : 'border-border bg-muted text-muted-foreground'">{{ statusLabel(workflow.is_active) }}</Badge></div>
                <div class="mt-5 space-y-3">
                    <div v-for="version in workflow.versions" :key="version.version" class="rounded-md border bg-muted/20 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2"><div class="flex items-center gap-2"><span class="font-medium">Version {{ version.version }}</span><Badge v-if="version.is_active" variant="outline" class="border-schedule/30 bg-schedule/10 text-schedule">Activated</Badge></div><Form v-if="!version.is_active" v-bind="activate.form([organization?.slug ?? '', workflow.id, version.version])" v-slot="{ processing }"><Button type="submit" size="sm" :disabled="processing"><Power /> Activate</Button></Form></div>
                        <p class="mt-2 text-xs text-muted-foreground">{{ version.require_distinct_approvers ? 'Distinct approvers required' : 'Approvers may repeat' }}</p>
                        <ol class="mt-4 space-y-2"><li v-for="step in version.steps" :key="step.sequence" class="flex gap-3 text-sm"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-muted text-xs">{{ step.sequence }}</span><div><p class="font-medium">{{ step.label }}</p><p class="text-xs text-muted-foreground">{{ selectorLabel(step) }} · {{ step.academic_unit_name ?? 'Organization-wide' }}</p></div></li></ol>
                    </div>
                </div>
                <Form v-if="workflow.is_active" v-bind="retire.form([organization?.slug ?? '', workflow.id])" class="mt-4" v-slot="{ processing }"><Button type="submit" variant="outline" size="sm" :disabled="processing"><Power /> Retire workflow</Button></Form>
            </section>
        </div>
    </div>
</template>
