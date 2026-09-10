<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ValidationSummary from '@/components/ValidationSummary.vue';
import { store } from '@/routes/resources/faculty';

const props = defineProps<{
    organizationSlug: string;
    employmentTypes: { value: string; label: string }[];
    units: { id: string; label: string }[];
}>();
const emit = defineEmits<{ created: [] }>();
const open = ref(false);
const form = useForm({
    resource_name: '',
    employee_number: '',
    position: '',
    employment_type: '',
    academic_unit_id: '',
    maximum_daily_minutes: '',
    maximum_weekly_minutes: '',
});
const errors = computed<Record<string, string>>(() => form.errors);
const fieldClass = 'mt-1.5 h-10 min-w-0';
const selectClass =
    'mt-1.5 h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]';
function submit(): void {
    form.submit(store(props.organizationSlug), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.clearErrors();
            open.value = false;
            emit('created');
        },
    });
}
</script>
<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button type="button"
                ><Plus class="size-4" />Add teacher</Button
            ></DialogTrigger
        >
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
            @interact-outside="
                (event) => {
                    if (form.processing) event.preventDefault();
                }
            "
            @escape-key-down="
                (event) => {
                    if (form.processing) event.preventDefault();
                }
            "
            :show-close-button="!form.processing"
        >
            <DialogHeader>
                <DialogTitle>Add teacher</DialogTitle>
                <DialogDescription
                    >Add a teacher to your faculty directory. You can set
                    teaching load limits now or leave them
                    blank.</DialogDescription
                >
            </DialogHeader>
            <form @submit.prevent="submit">
                <fieldset :disabled="form.processing" class="min-w-0">
                    <ValidationSummary
                        :errors="errors"
                        class="mb-4"
                        title="Check the teacher details"
                        :field-labels="{
                            resource_name: 'Teacher name',
                            maximum_daily_minutes: 'Daily teaching limit',
                            maximum_weekly_minutes: 'Weekly teaching limit',
                            load_limits: 'Teaching load limits',
                        }"
                        :field-ids="{
                            resource_name: 'faculty-resource-name',
                            employee_number: 'employee-number',
                            position: 'faculty-position',
                            employment_type: 'employment-type',
                            academic_unit_id: 'faculty-unit',
                            maximum_daily_minutes: 'daily-limit',
                            maximum_weekly_minutes: 'weekly-limit',
                            load_limits: 'faculty-load-limits',
                        }"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="faculty-resource-name"
                                >Teacher name</Label
                            ><Input
                                id="faculty-resource-name"
                                name="resource_name"
                                v-model="form.resource_name"
                                :class="fieldClass"
                                placeholder="Dr. Ada Lovelace"
                                required
                                :aria-invalid="Boolean(errors.resource_name)"
                                aria-describedby="faculty-name-error"
                            /><InputError
                                id="faculty-name-error"
                                :message="errors.resource_name"
                            />
                        </div>
                        <details
                            :open="
                                Boolean(
                                    errors.employee_number ||
                                    errors.position ||
                                    errors.employment_type ||
                                    errors.academic_unit_id,
                                )
                            "
                            class="rounded-md border p-3 sm:col-span-2"
                        >
                            <summary class="cursor-pointer text-sm font-medium">
                                Employment and academic unit (optional)
                            </summary>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label for="employee-number"
                                        >Employee number</Label
                                    ><Input
                                        id="employee-number"
                                        name="employee_number"
                                        v-model="form.employee_number"
                                        :class="fieldClass"
                                        placeholder="FAC-001"
                                    /><InputError
                                        :message="errors.employee_number"
                                    />
                                </div>
                                <div>
                                    <Label for="faculty-position"
                                        >Position</Label
                                    ><Input
                                        id="faculty-position"
                                        name="position"
                                        v-model="form.position"
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
                                        v-model="form.employment_type"
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
                                        v-model="form.academic_unit_id"
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
                            </div>
                        </details>
                    </div>
                    <fieldset
                        id="faculty-load-limits"
                        tabindex="-1"
                        class="mt-6 min-w-0 rounded-lg border bg-muted/20 p-4 focus-visible:outline-2 focus-visible:outline-ring"
                        aria-describedby="faculty-load-help"
                    >
                        <legend class="px-1 text-sm font-semibold">
                            Teaching load limits
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            >
                        </legend>
                        <p
                            id="faculty-load-help"
                            class="text-sm leading-5 text-muted-foreground"
                        >
                            The maximum time this teacher can teach per day or
                            week. Leave either field blank if no limit is
                            needed.
                        </p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label for="daily-limit"
                                    >Daily teaching limit (minutes)</Label
                                >
                                <Input
                                    id="daily-limit"
                                    name="maximum_daily_minutes"
                                    v-model="form.maximum_daily_minutes"
                                    type="number"
                                    min="1"
                                    step="1"
                                    :class="fieldClass"
                                    placeholder="No limit"
                                    :aria-invalid="
                                        Boolean(errors.maximum_daily_minutes)
                                    "
                                    aria-describedby="daily-limit-help daily-limit-error"
                                />
                                <p
                                    id="daily-limit-help"
                                    class="mt-1.5 text-xs text-muted-foreground"
                                >
                                    For example, 480 minutes = 8 hours per day.
                                </p>
                                <InputError
                                    id="daily-limit-error"
                                    :message="errors.maximum_daily_minutes"
                                />
                            </div>
                            <div>
                                <Label for="weekly-limit"
                                    >Weekly teaching limit (minutes)</Label
                                >
                                <Input
                                    id="weekly-limit"
                                    name="maximum_weekly_minutes"
                                    v-model="form.maximum_weekly_minutes"
                                    type="number"
                                    min="1"
                                    step="1"
                                    :class="fieldClass"
                                    placeholder="No limit"
                                    :aria-invalid="
                                        Boolean(errors.maximum_weekly_minutes)
                                    "
                                    aria-describedby="weekly-limit-help weekly-limit-error"
                                />
                                <p
                                    id="weekly-limit-help"
                                    class="mt-1.5 text-xs text-muted-foreground"
                                >
                                    For example, 2400 minutes = 40 hours per
                                    week. Must be at least the daily limit when
                                    both are set.
                                </p>
                                <InputError
                                    id="weekly-limit-error"
                                    :message="errors.maximum_weekly_minutes"
                                />
                            </div>
                        </div>
                        <InputError
                            :message="errors.load_limits"
                            class="mt-2"
                        />
                    </fieldset>
                </fieldset>
                <DialogFooter class="mt-6 gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false"
                        >Close</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        form.processing ? 'Saving...' : 'Save teacher'
                    }}</Button>
                </DialogFooter>
                <p class="mt-3 text-xs text-muted-foreground">
                    Closing keeps your draft until you leave this page.
                </p>
            </form>
        </DialogContent>
    </Dialog>
</template>
