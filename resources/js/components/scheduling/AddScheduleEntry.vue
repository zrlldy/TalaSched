<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import MinuteTimeInput from '@/components/MinuteTimeInput.vue';
import SchedulingConflictList from '@/components/scheduling/SchedulingConflictList.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { saveScheduleEntry, schedulingError } from '@/lib/scheduling';
import { setup } from '@/routes/resources';
import { store } from '@/routes/scheduling/entries';
import type { ScheduleIssue, ScheduleOfferingOption } from '@/types';

const props = defineProps<{
    organizationSlug: string;
    versionId: string;
    timezone: string;
    offerings: ScheduleOfferingOption[];
    rooms: { id: string; name: string }[];
}>();
const emit = defineEmits<{ created: [id: string] }>();
const open = ref(false);
const offeringId = ref('');
const instructorId = ref('');
const roomId = ref('');
const message = ref('');
const issues = ref<ScheduleIssue[]>([]);
const offering = computed(() =>
    props.offerings.find((option) => option.id === offeringId.value),
);
const pendingCommand = ref<{ signature: string; key: string } | null>(null);
const saving = ref(false);
type ClassPayload = {
    timetable_version_id: string;
    offering_component_id: string;
    weekday: number;
    starts_at_minute: number | '';
    ends_at_minute: number | '';
    delivery_mode: string;
    notes: string;
    resources: { resource_id: string; role: string }[];
};
const initialPayload = (): ClassPayload => ({
    timetable_version_id: props.versionId,
    offering_component_id: '',
    weekday: 1,
    starts_at_minute: 480,
    ends_at_minute: 540,
    delivery_mode: 'physical',
    notes: '',
    resources: [],
});
const form = reactive(initialPayload());
watch(offering, (option) => {
    instructorId.value =
        option?.instructors.length === 1 ? option.instructors[0].id : '';
    form.delivery_mode = option?.delivery_mode ?? 'physical';
});
watch([offering, () => form.starts_at_minute], ([option, start]) => {
    if (option && start !== '') {
        form.ends_at_minute = Math.min(1440, start + option.duration_minutes);
    }
});

const submit = async (): Promise<void> => {
    if (saving.value) {
        return;
    }

    message.value = '';
    issues.value = [];

    if (!offering.value || !instructorId.value) {
        message.value =
            'Choose a class and an eligible instructor before saving.';

        return;
    }

    form.timetable_version_id = props.versionId;
    form.offering_component_id = offering.value.id;
    form.resources = [
        { resource_id: offering.value.group.id, role: 'student_group' },
        { resource_id: instructorId.value, role: 'instructor' },
        ...(roomId.value ? [{ resource_id: roomId.value, role: 'room' }] : []),
    ];
    const signature = JSON.stringify(form);

    if (pendingCommand.value?.signature !== signature) {
        pendingCommand.value = { signature, key: crypto.randomUUID() };
    }

    saving.value = true;

    try {
        const id = await saveScheduleEntry(
            store(props.organizationSlug),
            form,
            pendingCommand.value.key,
        );
        open.value = false;
        pendingCommand.value = null;
        Object.assign(form, initialPayload());
        offeringId.value = '';
        instructorId.value = '';
        roomId.value = '';
        emit('created', id);
    } catch (error) {
        const failure = schedulingError(error);
        message.value = failure.message;
        issues.value = failure.issues;
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button type="button"
                ><Plus class="size-4" />Add class</Button
            ></DialogTrigger
        >
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Add a weekly class</DialogTitle>
                <DialogDescription
                    >Choose a subject, instructor, and time. Times are in
                    {{ timezone }}; conflicts are checked when you
                    save.</DialogDescription
                >
            </DialogHeader>
            <div v-if="offerings.length === 0" class="space-y-3 py-3">
                <p class="text-sm text-muted-foreground">
                    This period has no active subject offerings. Set up a
                    subject offering and its student group before adding
                    classes.
                </p>
                <Button variant="outline" as-child
                    ><Link
                        :href="
                            setup(organizationSlug, {
                                query: { section: 'offerings' },
                            })
                        "
                        >Open subject offerings</Link
                    ></Button
                >
            </div>
            <form v-else class="grid gap-4" @submit.prevent="submit">
                <fieldset
                    :disabled="saving"
                    class="grid gap-4 disabled:opacity-60"
                >
                    <div class="grid gap-1.5">
                        <Label for="new-class-offering"
                            >Class and student group</Label
                        >
                        <select
                            id="new-class-offering"
                            v-model="offeringId"
                            required
                            class="h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>
                                Choose a subject offering
                            </option>
                            <option
                                v-for="option in offerings"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }} / {{ option.group.name }}
                            </option>
                        </select>
                        <p
                            v-if="offering"
                            class="text-xs text-muted-foreground"
                        >
                            {{ offering.duration_minutes }} minutes per session.
                            {{ offering.group.name }} is assigned automatically.
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-class-instructor">Instructor</Label>
                        <select
                            id="new-class-instructor"
                            v-model="instructorId"
                            required
                            :disabled="!offering"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50"
                        >
                            <option value="" disabled>
                                Choose an eligible instructor
                            </option>
                            <option
                                v-for="instructor in offering?.instructors ??
                                []"
                                :key="instructor.id"
                                :value="instructor.id"
                            >
                                {{ instructor.name }}
                            </option>
                        </select>
                        <p
                            v-if="offering && offering.instructors.length === 0"
                            class="text-sm text-warning"
                        >
                            No eligible instructors are assigned yet.
                            <Link
                                class="underline underline-offset-4"
                                :href="
                                    setup(organizationSlug, {
                                        query: { section: 'offerings' },
                                    })
                                "
                                >Open teaching team setup</Link
                            >
                            or ask your scheduling administrator.
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-class-day">Weekday</Label>
                        <select
                            id="new-class-day"
                            v-model.number="form.weekday"
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option
                                v-for="(day, index) in [
                                    'Monday',
                                    'Tuesday',
                                    'Wednesday',
                                    'Thursday',
                                    'Friday',
                                    'Saturday',
                                    'Sunday',
                                ]"
                                :key="day"
                                :value="index + 1"
                            >
                                {{ day }}
                            </option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <MinuteTimeInput
                            id="new-class-start"
                            v-model="form.starts_at_minute"
                            name="starts_at_minute"
                            label="Start time"
                            required
                        />
                        <MinuteTimeInput
                            id="new-class-end"
                            v-model="form.ends_at_minute"
                            name="ends_at_minute"
                            label="End time"
                            required
                            allow-end-of-day
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-class-room">Room</Label>
                        <select
                            id="new-class-room"
                            v-model="roomId"
                            :required="
                                form.delivery_mode === 'physical' ||
                                form.delivery_mode === 'hybrid'
                            "
                            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">
                                {{
                                    form.delivery_mode === 'online' ||
                                    form.delivery_mode === 'outdoor'
                                        ? 'No room'
                                        : 'Choose a room'
                                }}
                            </option>
                            <option
                                v-for="room in rooms"
                                :key="room.id"
                                :value="room.id"
                            >
                                {{ room.name }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Delivery: {{ form.delivery_mode }}. Room capacity
                            and requirements are checked before saving.
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-class-notes">Notes (optional)</Label
                        ><textarea
                            id="new-class-notes"
                            v-model="form.notes"
                            maxlength="2000"
                            rows="2"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        />
                    </div>
                </fieldset>
                <p v-if="message" role="alert" class="text-sm text-conflict">
                    {{ message }}
                </p>
                <SchedulingConflictList
                    v-if="issues.length"
                    :issues="issues"
                    title="What needs attention"
                />
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="saving"
                        @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        :disabled="saving || !offering || !instructorId"
                        >{{
                            saving ? 'Checking and saving...' : 'Save class'
                        }}</Button
                    >
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
