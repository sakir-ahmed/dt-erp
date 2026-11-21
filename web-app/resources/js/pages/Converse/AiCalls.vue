<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    calls: { type: Object, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});

const page = usePage();
const labels = computed(() => page.props.labels.ai_calls);
const selected = ref(null);
const details = ref(null);
const failed = ref(false);
let detailRequest;

const providerNames = { ollama: 'Ollama', openrouter: 'OpenRouter', openai: 'OpenAI', deepseek: 'DeepSeek', anthropic: 'Anthropic' };
const numberFormat = computed(() => new Intl.NumberFormat(page.props.locale));
const costFormat = computed(() => new Intl.NumberFormat(page.props.locale, {
    style: 'currency', currency: 'USD', maximumFractionDigits: 10,
}));
const durationFormat = computed(() => new Intl.NumberFormat(page.props.locale, {
    style: 'unit', unit: 'second', maximumFractionDigits: 3,
}));
const dateFormat = computed(() => new Intl.DateTimeFormat(page.props.locale, {
    dateStyle: 'medium', timeStyle: 'medium', timeZone: 'Asia/Dhaka',
}));
const number = (value) => value == null ? labels.value.not_reported : numberFormat.value.format(value);
const cost = (value) => value == null ? labels.value.not_reported : costFormat.value.format(value);
const time = (value) => dateFormat.value.format(new Date(value));

const cards = computed(() => [
    { label: 'all_calls', value: number(props.totals.calls) },
    { label: 'free_calls', value: number(props.totals.free_calls) },
    { label: 'paid_calls', value: number(props.totals.paid_calls) },
    { label: 'reported_tokens', value: number(props.totals.tokens), note: labels.value.token_coverage + ' · ' + number(props.totals.calls_with_tokens) },
    { label: 'reported_cost', value: cost(props.totals.reported_cost_usd) },
]);
const detailFields = computed(() => {
    const call = details.value;
    if (!call) return [];

    return [
        ['provider', providerNames[call.provider]],
        ['model', call.model],
        ['status', labels.value[call.status]],
        ['billing', labels.value[call.billing]],
        ['time', time(call.called_at)],
        ['duration', durationFormat.value.format(call.duration_ms / 1000)],
        ['operation', labels.value[call.operation]],
        ['source', labels.value[call.origin]],
        ['agent', call.agent],
        ['workflow', call.workflow],
        ['http_status', call.http_status],
        ['reason', labels.value[call.detail.reason]],
        ['input_tokens', number(call.input_tokens)],
        ['output_tokens', number(call.output_tokens)],
        ['reported_tokens', number(call.total_tokens)],
        ['reported_cost', cost(call.reported_cost_usd)],
    ].filter(([, value]) => value != null);
});

function filter(key, value) {
    const filters = { ...props.filters, [key]: value };
    router.get('/converse/ai-calls', Object.fromEntries(Object.entries(filters).filter(([, entry]) => entry)), {
        preserveScroll: true,
    });
}

async function openCall(id) {
    detailRequest?.abort();
    const request = new AbortController();
    detailRequest = request;
    selected.value = id;
    details.value = null;
    failed.value = false;
    try {
        const response = await fetch(`/converse/ai-calls/${id}`, {
            headers: { Accept: 'application/json' }, cache: 'no-store', signal: request.signal,
        });
        if (!response.ok) throw new Error('Could not load AI call details');
        const call = await response.json();
        if (!request.signal.aborted) details.value = call;
    } catch {
        if (!request.signal.aborted) failed.value = true;
    }
}

function closeCall() {
    detailRequest?.abort();
    selected.value = null;
    details.value = null;
}

onBeforeUnmount(closeCall);
</script>

<template>
    <AdminLayout>
        <Head :title="page.props.labels.ai_call_history" />
        <PageHeader :title="page.props.labels.ai_call_history" />

        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div v-for="card in cards" :key="card.label" class="rounded-lg border border-line bg-surface p-3 sm:p-4">
                <dt class="text-sm text-muted">{{ labels[card.label] }}</dt>
                <dd class="mt-1 break-words text-lg font-semibold tabular-nums">{{ card.value }}</dd>
                <dd v-if="card.note" class="mt-1 text-xs text-muted">{{ card.note }}</dd>
            </div>
        </dl>

        <section class="mt-4 rounded-lg border border-line bg-surface">
            <div class="flex flex-wrap gap-3 border-b border-line p-3">
                <label v-for="(options, key) in filterOptions" :key="key" class="grid gap-1 text-sm font-medium">
                    {{ labels[key] }}
                    <select :value="filters[key]" class="ui-input min-w-32" @change="filter(key, $event.target.value)">
                        <option value="">{{ labels.all }}</option>
                        <option v-for="option in options" :key="option" :value="option">{{ providerNames[option] ?? labels[option] }}</option>
                    </select>
                </label>
            </div>

            <div v-if="calls.data.length" class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead class="bg-page text-muted">
                        <tr>
                            <th v-for="heading in ['time', 'provider', 'source', 'billing', 'reported_tokens', 'reported_cost', 'duration', 'status']" :key="heading" scope="col" class="whitespace-nowrap px-3 py-2 text-start font-medium">{{ labels[heading] }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="call in calls.data" :key="call.id" class="cursor-pointer hover:bg-page focus-within:bg-page" @click="openCall(call.id)">
                            <td class="whitespace-nowrap px-3 py-2">
                                <button type="button" class="cursor-pointer text-brand underline-offset-4 hover:underline focus:underline" aria-haspopup="dialog" :aria-label="`${labels.details} #${call.id}`" @click.stop="openCall(call.id)">
                                    <time :datetime="call.called_at">{{ time(call.called_at) }}</time>
                                </button>
                            </td>
                            <td class="min-w-40 px-3 py-2"><strong class="font-medium">{{ providerNames[call.provider] }}</strong><span class="block break-all text-xs text-muted">{{ call.model }}</span></td>
                            <td class="min-w-32 px-3 py-2">{{ labels[call.origin] }}<span class="block text-xs text-muted">{{ labels[call.operation] }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2">{{ labels[call.billing] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ number(call.total_tokens) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ cost(call.reported_cost_usd) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ durationFormat.format(call.duration_ms / 1000) }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="rounded-full px-2 py-1 text-xs" :class="call.status === 'accepted' ? 'bg-success/10 text-success' : 'bg-page text-muted'">{{ labels[call.status] }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="p-5 text-sm text-muted">{{ labels.empty }}</p>

            <div v-if="calls.prev_page_url || calls.next_page_url" class="flex justify-end gap-2 border-t border-line p-3">
                <Link v-if="calls.prev_page_url" :href="calls.prev_page_url" class="ui-button">{{ page.props.labels.previous }}</Link>
                <Link v-if="calls.next_page_url" :href="calls.next_page_url" class="ui-button">{{ page.props.labels.next }}</Link>
            </div>
        </section>

        <SlideOver v-if="selected !== null" :title="`${labels.details} #${selected}`" :close-label="page.props.labels.close" @close="closeCall">
            <div v-if="failed" role="alert" class="flex items-center gap-2">
                <p>{{ labels.error }}</p>
                <button type="button" class="ui-button" @click="openCall(selected)">{{ labels.retry }}</button>
            </div>
            <p v-else-if="!details" role="status" class="text-sm text-muted">{{ labels.loading }}</p>
            <div v-else class="space-y-4">
                <dl class="grid grid-cols-2 gap-3 rounded-lg border border-line p-3 text-sm">
                    <div v-for="[key, value] in detailFields" :key="key" class="min-w-0">
                        <dt class="text-muted">{{ labels[key] }}</dt>
                        <dd class="break-words font-medium">{{ value }}</dd>
                    </div>
                </dl>
                <Link v-if="details.conversation_id && page.props.canViewConverseInbox" :href="`/converse/inbox/${details.conversation_id}`" class="ui-button">{{ labels.conversation }} #{{ details.conversation_id }}</Link>
                <p class="text-sm text-muted">{{ labels.redaction }}</p>
                <section v-for="field in ['instructions', 'input', 'output']" :key="field">
                    <h3 class="mb-1 text-sm font-semibold">{{ labels[field] }}</h3>
                    <pre class="max-h-80 overflow-auto rounded-lg border border-line bg-page p-3 text-xs break-words whitespace-pre-wrap">{{ details.detail[field] ?? labels.not_reported }}</pre>
                </section>
            </div>
        </SlideOver>
    </AdminLayout>
</template>
