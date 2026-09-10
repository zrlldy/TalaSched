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
import { store } from '@/routes/academic/groups';
import type { AcademicUnitOption, AcademicYearOption } from '@/types/academic';

const props = defineProps<{
    organizationSlug: string;
    years: AcademicYearOption[];
    units: AcademicUnitOption[];
}>();
const emit = defineEmits<{ created: [] }>();
const open = ref(false);
const form = useForm({
    academic_year_id: '',
    academic_unit_id: '',
    code: '',
    name: '',
    expected_headcount: 0,
});
const errors = computed<Record<string, string>>(() => form.errors);
const openYears = computed(() =>
    props.years.filter((year) => year.status !== 'closed'),
);
const selectClass =
    'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
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
                ><Plus class="size-4" />Add student group</Button
            ></DialogTrigger
        >
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-xl"
            :show-close-button="!form.processing"
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
        >
            <DialogHeader
                ><DialogTitle>Add a student group</DialogTitle
                ><DialogDescription
                    >Create a class or section, such as Grade 7 - Section A or
                    BSCS 1A. After saving, use Manage group to choose its
                    participating periods.</DialogDescription
                ></DialogHeader
            >
            <form @submit.prevent="submit" class="space-y-5">
                <ValidationSummary
                    :errors="errors"
                    title="Check the student group details"
                    :field-labels="{
                        academic_year_id: 'Academic year',
                        academic_unit_id: 'School unit',
                        code: 'Group code',
                        name: 'Group name',
                        expected_headcount: 'Expected students',
                    }"
                    :field-ids="{
                        academic_year_id: 'group-year',
                        academic_unit_id: 'group-unit',
                        code: 'group-code',
                        name: 'group-name',
                        expected_headcount: 'group-headcount',
                    }"
                />
                <fieldset
                    :disabled="form.processing"
                    class="grid min-w-0 gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-1.5">
                        <Label for="group-year">Academic year</Label
                        ><select
                            id="group-year"
                            v-model="form.academic_year_id"
                            :class="selectClass"
                            required
                            :aria-invalid="Boolean(errors.academic_year_id)"
                            aria-describedby="group-year-error"
                        >
                            <option value="">Choose an open year</option>
                            <option
                                v-for="year in openYears"
                                :key="year.id"
                                :value="year.id"
                            >
                                {{ year.name }}
                            </option></select
                        ><InputError
                            id="group-year-error"
                            :message="errors.academic_year_id"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-unit">School unit</Label
                        ><select
                            id="group-unit"
                            v-model="form.academic_unit_id"
                            :class="selectClass"
                            required
                            :aria-invalid="Boolean(errors.academic_unit_id)"
                            aria-describedby="group-unit-error"
                        >
                            <option value="">
                                Choose a grade level or program
                            </option>
                            <option
                                v-for="unit in units"
                                :key="unit.id"
                                :value="unit.id"
                            >
                                {{ unit.name }} / {{ unit.type_name }}
                            </option></select
                        ><InputError
                            id="group-unit-error"
                            :message="errors.academic_unit_id"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-code">Group code</Label
                        ><Input
                            id="group-code"
                            v-model="form.code"
                            placeholder="BSCS-1A"
                            maxlength="64"
                            required
                            :aria-invalid="Boolean(errors.code)"
                            aria-describedby="group-code-help group-code-error"
                        />
                        <p
                            id="group-code-help"
                            class="text-xs text-muted-foreground"
                        >
                            Use a unique code within this academic year.
                        </p>
                        <InputError
                            id="group-code-error"
                            :message="errors.code"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-name">Group name</Label
                        ><Input
                            id="group-name"
                            v-model="form.name"
                            placeholder="BS Computer Science - 1A"
                            maxlength="255"
                            required
                            :aria-invalid="Boolean(errors.name)"
                            aria-describedby="group-name-error"
                        /><InputError
                            id="group-name-error"
                            :message="errors.name"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="group-headcount">Expected students</Label
                        ><Input
                            id="group-headcount"
                            v-model="form.expected_headcount"
                            type="number"
                            min="0"
                            step="1"
                            required
                            :aria-invalid="Boolean(errors.expected_headcount)"
                            aria-describedby="group-headcount-help group-headcount-error"
                        />
                        <p
                            id="group-headcount-help"
                            class="text-xs text-muted-foreground"
                        >
                            Enter 0 if the count is not known yet.
                        </p>
                        <InputError
                            id="group-headcount-error"
                            :message="errors.expected_headcount"
                        />
                    </div>
                </fieldset>
                <DialogFooter class="gap-2"
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false"
                        >Close</Button
                    ><Button type="submit" :disabled="form.processing">{{
                        form.processing
                            ? 'Creating group...'
                            : 'Create student group'
                    }}</Button></DialogFooter
                >
                <p class="text-xs text-muted-foreground">
                    Closing keeps your draft until you leave this page.
                </p>
            </form>
        </DialogContent>
    </Dialog>
</template>
