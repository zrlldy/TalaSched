<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ClipboardCheck, GitBranch, PenLine } from '@lucide/vue';
import { computed } from 'vue';
import { inbox, signatories, workflows } from '@/routes/approvals';

const props = defineProps<{
    organizationSlug: string;
    current: 'inbox' | 'workflows' | 'signatories';
    canManage: boolean;
}>();

const items = computed(() =>
    [
        {
            key: 'inbox',
            label: 'Approval inbox',
            href: inbox(props.organizationSlug),
            icon: ClipboardCheck,
            visible: true,
        },
        {
            key: 'workflows',
            label: 'Workflow designer',
            href: workflows(props.organizationSlug),
            icon: GitBranch,
            visible: props.canManage,
        },
        {
            key: 'signatories',
            label: 'Signatory profiles',
            href: signatories(props.organizationSlug),
            icon: PenLine,
            visible: props.canManage,
        },
    ].filter((item) => item.visible),
);
</script>

<template>
    <nav
        aria-label="Approval navigation"
        class="flex flex-wrap gap-x-5 gap-y-1 border-b"
    >
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            :aria-current="current === item.key ? 'page' : undefined"
            class="flex min-h-11 items-center gap-2 border-b-2 px-1 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring"
            :class="
                current === item.key
                    ? 'border-schedule text-schedule'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
            "
        >
            <component :is="item.icon" class="size-4" />{{ item.label }}
        </Link>
    </nav>
</template>
