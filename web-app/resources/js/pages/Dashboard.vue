<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import EmptyState from '../components/ui-kit/EmptyState.vue';
import PageHeader from '../components/ui-kit/PageHeader.vue';
import AdminLayout from '../layouts/AdminLayout.vue';
import { formatDhakaDateTime, formatNumber } from '../lib/format';

const props = defineProps({
    name: { type: String, required: true },
    accountCount: { type: Number, default: null },
    converse: { type: Object, default: null },
    aiUsage: { type: Object, default: null },
    knowledge: { type: Object, default: null },
});

const page = usePage();
const labels = computed(() => page.props.labels);
const widgets = computed(() => labels.value.dashboard_widgets);
const number = (value, options) => formatNumber(value, page.props.locale, options);
const dateLabel = computed(() => new Intl.DateTimeFormat(page.props.locale, {
    day: 'numeric', month: 'short', timeZone: 'Asia/Dhaka',
}));
const weekdays = computed(() => new Intl.DateTimeFormat(page.props.locale, {
    weekday: 'short', timeZone: 'Asia/Dhaka',
}));
const dateFor = (day) => new Date(day + 'T00:00:00+06:00');

const indicators = [
    { key: 'new_today', icon: 'inbox' },
    { key: 'waiting_count', icon: 'bell', href: '#waiting-for-reply' },
    { key: 'open_demo_inquiries', icon: 'play', href: '/converse/inbox?status=open&tags=Demo' },
    { key: 'ai_replies_today', icon: 'cubes' },
];
const activity = computed(() => props.converse?.activity ?? []);
const activityTotal = computed(() => activity.value.reduce((total, day) => total + day.whatsapp + day.messenger, 0));
const peak = computed(() => Math.max(1, ...activity.value.map((day) => day.whatsapp + day.messenger)));
const activityDates = computed(() => activity.value.length
    ? dateLabel.value.formatRange(dateFor(activity.value[0].date), dateFor(activity.value.at(-1).date))
    : '');

function dailySummary(day) {
    return [
        dateLabel.value.format(dateFor(day.date)),
        labels.value.whatsapp + ' ' + number(day.whatsapp),
        labels.value.messenger + ' ' + number(day.messenger),
    ].join(' · ');
}
</script>

<template>
    <AdminLayout>
        <Head :title="labels.dashboard" />
        <PageHeader :title="labels.dashboard">
            <template #actions>
                <Link v-if="accountCount !== null" href="/users" class="ui-button">
                    <AppIcon name="users" />
                    {{ labels.accounts }} <span class="tabular-nums text-muted">{{ number(accountCount) }}</span>
                </Link>
                <Link v-if="converse" href="/converse/inbox" class="ui-button ui-button-primary">
                    <AppIcon name="inbox" />{{ widgets.open_inbox }}
                </Link>
            </template>
        </PageHeader>

        <div class="grid gap-4">
            <section v-if="converse" :aria-label="widgets.overview" class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <component v-for="{ key, icon, href } in indicators" :is="href?.startsWith('/') ? Link : href ? 'a' : 'div'"
                    :key="key" :href="href" class="min-w-0 rounded-xl border border-line bg-surface p-3 sm:p-4"
                    :class="{ 'hover:border-brand motion-safe:transition-colors': href }">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="key === 'waiting_count' && converse[key] > 0 ? 'bg-danger/10 text-danger' : 'bg-selected text-brand'">
                            <AppIcon :name="icon" />
                        </span>
                        <span class="text-sm font-medium text-muted">{{ widgets[key] }}</span>
                    </div>
                    <p class="mt-3 break-words text-2xl font-semibold tracking-tight tabular-nums">{{ number(converse[key]) }}</p>
                </component>
            </section>

            <div v-if="converse" class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section class="min-w-0 rounded-xl border border-line bg-surface p-4 sm:p-5" aria-labelledby="incoming-activity">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 id="incoming-activity" class="font-semibold">{{ widgets.activity }}</h2>
                            <p class="mt-1 text-xs text-muted">{{ activityDates }} · {{ widgets.dhaka }}</p>
                        </div>
                        <p class="text-sm text-muted"><strong class="text-base font-semibold tabular-nums text-ink">{{ number(activityTotal) }}</strong> {{ widgets.incoming_total }}</p>
                    </div>
                    <EmptyState v-if="activityTotal === 0" icon="chart-line" :title="widgets.no_activity" />
                    <template v-else>
                        <ul class="mt-5 flex flex-wrap gap-4 text-xs text-muted" :aria-label="widgets.channels">
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-success" aria-hidden="true" />{{ labels.whatsapp }}</li>
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-brand" aria-hidden="true" />{{ labels.messenger }}</li>
                        </ul>
                        <ol class="mt-4 grid grid-cols-7 gap-1 text-center sm:gap-3">
                            <li v-for="day in activity" :key="day.date" class="min-w-0" :title="dailySummary(day)">
                                <span class="sr-only">{{ dailySummary(day) }}</span>
                                <div class="flex h-32 flex-col justify-end border-b border-line sm:h-40" aria-hidden="true">
                                    <p class="mb-1 text-xs font-medium tabular-nums">{{ number(day.whatsapp + day.messenger) }}</p>
                                    <div class="mx-auto flex w-full max-w-10 flex-col-reverse overflow-hidden rounded-t-sm"
                                        :style="{ height: ((day.whatsapp + day.messenger) / peak * 85) + '%' }">
                                        <span class="bg-success" :style="{ flexGrow: day.whatsapp }" />
                                        <span class="bg-brand" :style="{ flexGrow: day.messenger }" />
                                    </div>
                                </div>
                                <time :datetime="day.date" class="mt-2 block text-xs text-muted" aria-hidden="true">{{ weekdays.format(dateFor(day.date)) }}</time>
                            </li>
                        </ol>
                    </template>
                </section>

                <section id="waiting-for-reply" class="scroll-mt-16 rounded-xl border border-line bg-surface" aria-labelledby="waiting-heading">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                        <h2 id="waiting-heading" class="font-semibold">{{ widgets.waiting_count }}</h2>
                        <span class="text-sm font-semibold tabular-nums text-muted">{{ number(converse.waiting_count) }}</span>
                    </header>
                    <EmptyState v-if="!converse.waiting.length" icon="inbox" :title="widgets.no_waiting" />
                    <ul v-else class="divide-y divide-line">
                        <li v-for="thread in converse.waiting" :key="thread.id">
                            <Link :href="'/converse/inbox/' + thread.id" class="flex min-h-12 items-center justify-between gap-2 px-4 py-2 hover:bg-page motion-safe:transition-colors">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ thread.name || widgets.chat + ' #' + number(thread.id) }}</span>
                                    <span class="block text-xs text-muted">{{ labels[thread.channel] }}</span>
                                </span>
                                <time :datetime="thread.last_message_at" class="shrink-0 text-end text-xs text-muted">{{ formatDhakaDateTime(thread.last_message_at, page.props.locale) }}</time>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>

            <div v-if="aiUsage || knowledge" class="grid items-start gap-4 xl:grid-cols-2">
                <section v-if="aiUsage" class="min-w-0 rounded-xl border border-line bg-surface p-4 sm:p-5" aria-labelledby="ai-usage">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 id="ai-usage" class="font-semibold">{{ widgets.ai_usage }}</h2>
                            <p class="mt-0.5 text-xs text-muted">{{ widgets.last_seven_days }}</p>
                        </div>
                        <Link href="/converse/ai-calls" class="ui-button">{{ labels.ai_call_history }}</Link>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div v-for="metric in ['calls', 'free_calls', 'paid_calls', 'unsuccessful_calls']" :key="metric" class="rounded-lg bg-page p-3">
                            <dt class="text-xs text-muted">{{ widgets[metric] }}</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">{{ number(aiUsage[metric]) }}</dd>
                        </div>
                        <div class="rounded-lg bg-page p-3">
                            <dt class="text-xs text-muted">{{ labels.ai_calls.reported_tokens }}</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums" v-if="aiUsage.tokens !== null">{{ number(aiUsage.tokens) }}</dd>
                            <dd v-else class="mt-1 text-sm text-muted">{{ labels.ai_calls.not_reported }}</dd>
                        </div>
                        <div class="rounded-lg bg-page p-3">
                            <dt class="text-xs text-muted">{{ labels.ai_calls.reported_cost }}</dt>
                            <dd class="mt-1 break-words text-lg font-semibold tabular-nums" v-if="aiUsage.reported_cost !== null">
                                {{ number(aiUsage.reported_cost, { style: 'currency', currency: 'USD', maximumFractionDigits: 10 }) }}
                            </dd>
                            <dd v-else class="mt-1 text-sm text-muted">{{ labels.ai_calls.not_reported }}</dd>
                        </div>
                    </dl>
                </section>

                <section v-if="knowledge" class="rounded-xl border border-line bg-surface p-4 sm:p-5" aria-labelledby="knowledge-health">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="knowledge-health" class="font-semibold">{{ widgets.knowledge_health }}</h2>
                        <Link href="/converse/knowledge" class="ui-button">{{ labels.knowledge }}</Link>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3">
                        <div v-for="metric in ['collections', 'indexed', 'queued', 'failed']" :key="metric" class="rounded-lg bg-page p-3">
                            <dt class="text-xs text-muted">{{ widgets[metric] }}</dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums" :class="{ 'text-danger': metric === 'failed' && knowledge.failed > 0 }">{{ number(knowledge[metric]) }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <section v-if="!converse && !aiUsage && !knowledge" class="rounded-xl border border-line bg-surface p-4">
                <p class="text-sm text-muted">{{ labels.signed_in_as }}</p>
                <p class="mt-1 wrap-anywhere font-semibold">{{ name }}</p>
            </section>
        </div>
    </AdminLayout>
</template>
