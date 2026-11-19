<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    agents: { type: Array, required: true },
    conversations: { type: Array, required: true },
    stats: { type: Object, required: true },
    filter: { type: String, default: '' },
});

const flash = computed(() => usePage().props.flash ?? null);

// A ceiling being reached matters more than one half full, so the bar changes
// colour on the way up rather than only at the top.
function loadClass(load) {
    if (load >= 1) return 'bg-rose-500';
    if (load >= 0.75) return 'bg-amber-500';
    return 'bg-emerald-500';
}

const statusLabels = {
    queued: 'Queued',
    assigned: 'Assigned',
    waiting_customer: 'Waiting on customer',
    resolved: 'Resolved',
    archived: 'Archived',
};

const statusClass = {
    queued: 'bg-slate-200 text-slate-700',
    assigned: 'bg-blue-100 text-blue-800',
    waiting_customer: 'bg-amber-100 text-amber-800',
    resolved: 'bg-emerald-100 text-emerald-800',
    archived: 'bg-slate-100 text-slate-500',
};

const reasonLabels = {
    nobody_online: 'Nobody online',
    all_at_capacity: 'Everyone at capacity',
    no_matching_skill: 'No matching skill',
    outside_office_hours: 'Outside office hours',
};

function hoursLeft(seconds) {
    return seconds > 0 ? Math.floor(seconds / 3600) : null;
}

function filterBy(status) {
    router.get('/', status ? { status } : {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Inbox" />

    <div class="min-h-full bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <h1 class="text-lg font-semibold">Beacon Support</h1>
                    <p class="text-sm text-slate-500">Omnichannel inbox · synthetic data</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        href="/route"
                        method="post"
                        as="button"
                        class="rounded-md bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        Run distributor
                    </Link>
                    <Link
                        href="/sweep"
                        method="post"
                        as="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100"
                    >
                        Presence sweep
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-6">
            <p v-if="flash" class="mb-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-900">
                {{ flash }}
            </p>

            <!-- Headroom is the number that explains a stalled queue at a
                 glance. Without it a supervisor sees an inbox that is not
                 moving and has to guess whether it is staffing or capacity. -->
            <section class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <div
                    v-for="(value, key) in stats"
                    :key="key"
                    class="rounded-lg border border-slate-200 bg-white p-4"
                >
                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ key }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</p>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
                <section class="rounded-lg border border-slate-200 bg-white">
                    <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3">
                        <h2 class="text-sm font-semibold">Conversations</h2>
                        <div class="ml-auto flex gap-1">
                            <button
                                v-for="option in ['', 'queued', 'assigned', 'waiting_customer']"
                                :key="option || 'all'"
                                class="rounded px-2 py-1 text-xs"
                                :class="filter === option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                                @click="filterBy(option)"
                            >
                                {{ option ? statusLabels[option] : 'All' }}
                            </button>
                        </div>
                    </div>

                    <ul class="divide-y divide-slate-100">
                        <li v-for="c in conversations" :key="c.id" class="px-4 py-3 hover:bg-slate-50">
                            <Link :href="`/conversations/${c.id}`" class="block">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ c.subject || 'No subject' }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ c.customer }} · {{ c.channel }} · {{ c.reference }}
                                        </p>
                                    </div>
                                    <span
                                        class="shrink-0 rounded px-2 py-0.5 text-xs font-medium"
                                        :class="statusClass[c.status]"
                                    >
                                        {{ statusLabels[c.status] }}
                                    </span>
                                </div>

                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                    <span v-if="c.agent" class="text-slate-600">{{ c.agent }}</span>

                                    <!-- Why the queue is not moving, on the row
                                         itself rather than left to inference. -->
                                    <span
                                        v-if="c.status === 'queued' && c.no_agent_reason"
                                        class="rounded bg-rose-50 px-1.5 py-0.5 text-rose-700"
                                    >
                                        {{ reasonLabels[c.no_agent_reason] }}
                                    </span>

                                    <span v-if="c.waiting_since" class="text-slate-400">
                                        queued {{ c.waiting_since }}
                                    </span>

                                    <!-- Shown before the agent opens the thread
                                         and starts typing, which is the whole
                                         point of computing it up front. -->
                                    <span
                                        v-if="c.send_kind === 'template_only'"
                                        class="rounded bg-slate-200 px-1.5 py-0.5 text-slate-700"
                                        title="Outside the reply window; only an approved template will be delivered"
                                    >
                                        template only
                                    </span>
                                    <span
                                        v-else-if="hoursLeft(c.window_seconds_left) !== null && hoursLeft(c.window_seconds_left) < 4"
                                        class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800"
                                    >
                                        window closes in {{ hoursLeft(c.window_seconds_left) }}h
                                    </span>
                                </div>
                            </Link>
                        </li>

                        <li v-if="conversations.length === 0" class="px-4 py-10 text-center text-sm text-slate-500">
                            Nothing here.
                        </li>
                    </ul>
                </section>

                <aside class="rounded-lg border border-slate-200 bg-white">
                    <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold">Agents</h2>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="a in agents" :key="a.id" class="px-4 py-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium">{{ a.name }}</span>
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="{
                                        'bg-emerald-100 text-emerald-800': a.presence === 'online',
                                        'bg-amber-100 text-amber-800': a.presence === 'away',
                                        'bg-slate-200 text-slate-600': a.presence === 'offline',
                                    }"
                                >
                                    {{ a.presence }}
                                </span>
                            </div>

                            <!-- Load as a RATIO. A senior holding 8 of 12 has
                                 more room than a trainee holding 3 of 3, and a
                                 raw count hides exactly that. -->
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded bg-slate-100">
                                <div
                                    class="h-full rounded transition-all"
                                    :class="loadClass(a.load)"
                                    :style="{ width: `${Math.min(100, a.load * 100)}%` }"
                                />
                            </div>
                            <p class="mt-1 text-xs text-slate-500 tabular-nums">
                                {{ a.open }} / {{ a.capacity }}
                                <span v-if="!a.routable" class="text-slate-400">· not routable</span>
                            </p>
                        </li>
                    </ul>
                </aside>
            </div>
        </main>
    </div>
</template>
