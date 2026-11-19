<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
    timeline: { type: Array, required: true },
});

const windowOpen = computed(() => props.conversation.send_kind === 'free_form');

const hoursLeft = computed(() => Math.floor(props.conversation.window_seconds_left / 3600));
</script>

<template>
    <Head :title="conversation.reference" />

    <div class="min-h-full bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-5xl px-6 py-4">
                <Link href="/" class="text-sm text-blue-600 hover:underline">&larr; Inbox</Link>
                <h1 class="mt-2 text-lg font-semibold">{{ conversation.subject || 'No subject' }}</h1>
                <p class="text-sm text-slate-500">
                    {{ conversation.customer }} · {{ conversation.channel }} · {{ conversation.reference }}
                    <span v-if="conversation.agent"> · handled by {{ conversation.agent }}</span>
                </p>
            </div>
        </header>

        <main class="mx-auto grid max-w-5xl gap-6 px-6 py-6 lg:grid-cols-[1fr_280px]">
            <section>
                <!-- The window state is shown ABOVE the composer, not reported
                     after a failed send. By then the agent believes they have
                     answered someone who never heard from them. -->
                <div
                    class="mb-4 rounded-md px-4 py-3 text-sm"
                    :class="windowOpen ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'"
                >
                    <template v-if="windowOpen">
                        Reply window open — closes in {{ hoursLeft }}h. Any message may be sent.
                    </template>
                    <template v-else>
                        Reply window closed. Only an approved template will be delivered.
                    </template>
                </div>

                <ul class="space-y-3">
                    <li v-for="m in messages" :key="m.id" class="flex" :class="m.direction === 'outbound' ? 'justify-end' : ''">
                        <div
                            class="max-w-[80%] rounded-lg px-4 py-2"
                            :class="m.direction === 'outbound' ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200'"
                        >
                            <p class="text-sm">{{ m.body }}</p>
                            <p class="mt-1 text-xs" :class="m.direction === 'outbound' ? 'text-blue-100' : 'text-slate-400'">
                                {{ m.author }} · {{ m.at }}
                            </p>
                        </div>
                    </li>
                </ul>

                <div class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                    <textarea
                        class="w-full resize-none rounded border border-slate-200 p-2 text-sm disabled:bg-slate-50"
                        rows="3"
                        :placeholder="windowOpen ? 'Write a reply…' : 'Outside the window — pick an approved template'"
                        :disabled="!windowOpen"
                    />
                    <p class="mt-2 text-xs text-slate-500">
                        Read-only demonstration: sending is exercised through the test suite and
                        <code>php artisan support:demo window</code>.
                    </p>
                </div>
            </section>

            <!-- The timeline answers "why did I get this?", which is the most
                 common question an agent asks about a routing system and one
                 the current state cannot answer. -->
            <aside class="rounded-lg border border-slate-200 bg-white">
                <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold">Timeline</h2>
                <ol class="divide-y divide-slate-100">
                    <li v-for="(event, index) in timeline" :key="index" class="px-4 py-3 text-xs">
                        <p class="font-medium">
                            {{ event.from || 'new' }} &rarr; {{ event.to }}
                        </p>
                        <p class="mt-0.5 text-slate-500">{{ event.actor }} · {{ event.at }}</p>
                        <p v-if="event.reason" class="mt-0.5 text-slate-400">{{ event.reason }}</p>
                    </li>
                </ol>
            </aside>
        </main>
    </div>
</template>
