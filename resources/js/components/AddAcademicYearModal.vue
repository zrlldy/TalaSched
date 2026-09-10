<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
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
import { store } from '@/routes/academic/years';

const props = defineProps<{ organizationSlug: string }>();
const emit = defineEmits<{ created: [] }>();
const open = ref(false);
const form = useForm(`academic-year:${props.organizationSlug}:create`, {
    name: '',
    starts_on: '',
    ends_on: '',
});
function submit(): void {
    form.submit(store(props.organizationSlug), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({ name: '', starts_on: '', ends_on: '' });
            form.reset();
            open.value = false;
            emit('created');
        },
    });
}
function preventWhileSaving(event: Event): void {
    if (form.processing) {
        event.preventDefault();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button type="button"
                ><Plus class="size-4" />Add academic year</Button
            ></DialogTrigger
        >
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-lg"
            :show-close-button="!form.processing"
            @interact-outside="preventWhileSaving"
            @escape-key-down="preventWhileSaving"
        >
            <DialogHeader
                ><DialogTitle>Add an academic year</DialogTitle
                ><DialogDescription
                    >Create a draft year, add its periods, then activate it when
                    the dates are ready.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-5" @submit.prevent="submit">
                <ValidationSummary
                    :errors="form.errors"
                    title="Check the academic year"
                    :field-ids="{
                        name: 'year-name',
                        starts_on: 'year-start',
                        ends_on: 'year-end',
                    }"
                    :field-labels="{
                        name: 'Year name',
                        starts_on: 'Start date',
                        ends_on: 'End date',
                    }"
                />
                <fieldset class="grid gap-4" :disabled="form.processing">
                    <div class="grid gap-1.5">
                        <Label for="year-name">Year name</Label
                        ><Input
                            id="year-name"
                            v-model="form.name"
                            placeholder="2026-2027"
                            maxlength="255"
                            required
                            :aria-invalid="Boolean(form.errors.name)"
                            aria-describedby="year-name-error"
                        /><InputError
                            id="year-name-error"
                            :message="form.errors.name"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="year-start">Start date</Label
                            ><Input
                                id="year-start"
                                v-model="form.starts_on"
                                type="date"
                                required
                                :aria-invalid="Boolean(form.errors.starts_on)"
                                aria-describedby="year-start-error"
                            /><InputError
                                id="year-start-error"
                                :message="form.errors.starts_on"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="year-end">End date</Label
                            ><Input
                                id="year-end"
                                v-model="form.ends_on"
                                type="date"
                                required
                                :aria-invalid="Boolean(form.errors.ends_on)"
                                aria-describedby="year-end-error"
                            /><InputError
                                id="year-end-error"
                                :message="form.errors.ends_on"
                            />
                        </div>
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
                            ? 'Creating year...'
                            : 'Create draft year'
                    }}</Button></DialogFooter
                >
                <p class="text-xs text-muted-foreground">
                    Closing keeps your draft.
                </p>
            </form>
        </DialogContent>
    </Dialog>
</template>
