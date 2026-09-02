<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Check,
    CircleAlert,
    Gauge,
    LockKeyhole,
    ReceiptText,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import WorkspacePageHeader from '@/components/WorkspacePageHeader.vue';
import WorkspaceState from '@/components/WorkspaceState.vue';
import { dashboard } from '@/routes';
import { show } from '@/routes/subscriptions';
import type { Organization } from '@/types';

type Subscription = {
    plan: { code: string; name: string };
    status: string;
    status_label: string;
    period_starts_at: string | null;
    period_ends_at: string | null;
    trial_ends_at: string | null;
    grace_ends_at: string | null;
};
type Feature = { key: string; label: string; enabled: boolean };
type Limit = {
    key: string;
    label: string;
    limit: number | null;
    current: number | null;
    remaining: number | null;
};

const props = defineProps<{
    subscription: Subscription | null;
    features: Feature[];
    limits: Limit[];
}>();
const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as Organization | null,
);
const unavailableFeatures = computed(() =>
    props.features.filter((feature) => !feature.enabled),
);
const formatDate = (value: string | null): string =>
    value === null
        ? 'Not set'
        : new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          }).format(new Date(value));
const usagePercentage = (limit: Limit): number => {
    if (limit.current === null || limit.limit === null || limit.limit === 0) {
        return 0;
    }

    return Math.min(100, (limit.current / limit.limit) * 100);
};

defineOptions({
    layout: (layoutProps: { currentOrganization?: Organization | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(layoutProps.currentOrganization?.slug ?? '')
                    .url,
            },
            {
                title: 'Plan & usage',
                href: show(layoutProps.currentOrganization?.slug ?? '').url,
            },
        ],
    }),
});
</script>

<template>
    <Head title="Plan & usage" />

    <div class="space-y-8">
        <WorkspacePageHeader
            section="Organization settings"
            title="Plan & usage"
            description="Review the current subscription, included capabilities, and organization capacity without exposing billing-provider data."
        >
            <template #metadata>
                <span>{{ organization?.name ?? 'Organization' }}</span>
                <span>Read-only billing context</span>
            </template>
        </WorkspacePageHeader>

        <WorkspaceState
            v-if="subscription === null"
            variant="empty"
            title="No subscription record"
            description="This organization has no current plan record. Feature access remains unavailable until an administrator provisions one."
        />

        <template v-else>
            <section
                class="relative isolate overflow-hidden rounded-lg border border-sidebar-border bg-sidebar p-5 text-sidebar-foreground sm:p-7"
            >
                <div
                    aria-hidden="true"
                    class="absolute -right-16 -bottom-16 -z-10 size-48 rounded-full border-[1.25rem] border-sidebar-primary/15"
                />
                <div
                    class="flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p
                            class="font-mono text-[0.6875rem] font-semibold tracking-[0.18em] text-sidebar-primary uppercase"
                        >
                            Current plan
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <h2
                                class="text-2xl font-semibold tracking-[-0.025em]"
                            >
                                {{ subscription.plan.name }}
                            </h2>
                            <Badge
                                class="border-sidebar-primary/30 bg-sidebar-primary/15 text-sidebar-primary"
                                variant="outline"
                                >{{ subscription.status_label }}</Badge
                            >
                        </div>
                        <p
                            class="mt-2 font-mono text-xs text-sidebar-foreground/60"
                        >
                            {{ subscription.plan.code }}
                        </p>
                    </div>
                    <dl class="grid gap-3 text-sm sm:min-w-72 sm:grid-cols-2">
                        <div>
                            <dt class="text-sidebar-foreground/60">
                                Current period
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{ formatDate(subscription.period_starts_at) }}
                                <span aria-hidden="true">→</span>
                                {{ formatDate(subscription.period_ends_at) }}
                            </dd>
                        </div>
                        <div
                            v-if="
                                subscription.trial_ends_at ||
                                subscription.grace_ends_at
                            "
                        >
                            <dt class="text-sidebar-foreground/60">
                                {{
                                    subscription.trial_ends_at
                                        ? 'Trial ends'
                                        : 'Grace ends'
                                }}
                            </dt>
                            <dd class="mt-1 font-medium">
                                {{
                                    formatDate(
                                        subscription.trial_ends_at ??
                                            subscription.grace_ends_at,
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section aria-labelledby="features-heading">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <p
                            class="font-mono text-[0.6875rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            Capability ledger
                        </p>
                        <h2
                            id="features-heading"
                            class="mt-1 text-xl font-semibold tracking-[-0.02em]"
                        >
                            Included features
                        </h2>
                    </div>
                    <ReceiptText
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="feature in features"
                        :key="feature.key"
                        class="flex items-start gap-3 rounded-lg border bg-card p-4"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-md border"
                            :class="
                                feature.enabled
                                    ? 'border-available/25 bg-available/10 text-available'
                                    : 'border-muted bg-muted text-muted-foreground'
                            "
                        >
                            <Check
                                v-if="feature.enabled"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <LockKeyhole
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold">
                                {{ feature.label }}
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    feature.enabled
                                        ? 'Included in this plan'
                                        : 'Not included in this plan'
                                }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <section aria-labelledby="limits-heading">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <p
                            class="font-mono text-[0.6875rem] font-semibold tracking-[0.18em] text-muted-foreground uppercase"
                        >
                            Capacity ledger
                        </p>
                        <h2
                            id="limits-heading"
                            class="mt-1 text-xl font-semibold tracking-[-0.02em]"
                        >
                            Plan limits
                        </h2>
                    </div>
                    <Gauge
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <article
                        v-for="limit in limits"
                        :key="limit.key"
                        class="rounded-lg border bg-card p-5"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="font-semibold">{{ limit.label }}</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    <template
                                        v-if="
                                            limit.limit !== null &&
                                            limit.current !== null
                                        "
                                        >{{ limit.current }} used of
                                        {{ limit.limit }} ·
                                        {{ limit.remaining }}
                                        remaining</template
                                    >
                                    <template v-else
                                        >Not configured for this plan</template
                                    >
                                </p>
                            </div>
                            <Badge
                                v-if="limit.limit !== null"
                                variant="outline"
                                >{{ limit.limit }}</Badge
                            >
                        </div>
                        <div
                            v-if="
                                limit.limit !== null && limit.current !== null
                            "
                            class="mt-5 h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-schedule transition-[width]"
                                :style="{ width: `${usagePercentage(limit)}%` }"
                            />
                        </div>
                    </article>
                </div>
            </section>

            <section
                v-if="unavailableFeatures.length > 0"
                class="flex gap-4 rounded-lg border border-warning/30 bg-warning/10 p-5"
                aria-labelledby="plan-change-heading"
            >
                <CircleAlert
                    class="mt-0.5 size-5 shrink-0 text-warning"
                    aria-hidden="true"
                />
                <div>
                    <h2 id="plan-change-heading" class="font-semibold">
                        Need an unavailable capability?
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-muted-foreground">
                        Plan changes are not self-service in this environment.
                        This page shows the server-enforced plan only; configure
                        a billing provider or apply an approved subscription
                        change before relying on unavailable features.
                    </p>
                </div>
            </section>
        </template>
    </div>
</template>
