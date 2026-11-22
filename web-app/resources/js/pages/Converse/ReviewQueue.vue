<script setup>
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, useId, watch } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { formatDhakaDateTime, formatNumber } from '../../lib/format';

const props = defineProps({
    status: { type: String, required: true },
    tasks: { type: Object, required: true },
    selected: { type: Object, default: null },
    pendingCount: { type: Number, default: null },
});
const page = usePage();
const labels = computed(() => page.props.labels);
const review = computed(() => labels.value.ai_review);
const formId = useId();
const reply = useForm({ body: '', operation_id: '' });
const close = useForm({});
const busy = computed(() => reply.processing || close.processing);
const count = computed(() => props.pendingCount ?? props.tasks.total);
const base = '/converse/review-queue';
const listUrl = computed(() => base + (props.status === 'closed' ? '?status=closed' : '') + (props.tasks.current_page > 1
    ? (props.status === 'closed' ? '&' : '?') + 'page=' + props.tasks.current_page : ''));
const canSend = computed(() => props.selected?.state === 'pending' && !props.selected.blocked_reason
    && [null, 'failed'].includes(props.selected.delivery_status)
    && [null, 'failed'].includes(props.selected.ai_delivery_status));
usePoll(15000, { only: ['tasks', 'pendingCount', 'selected'] });

watch(() => props.selected?.id, () => {
    reply.reset();
    reply.clearErrors();
    close.clearErrors();
});

function taskUrl(task) {
    const query = new URLSearchParams({ task: task.id });
    if (props.status === 'closed') query.set('status', 'closed');
    if (props.tasks.current_page > 1) query.set('page', props.tasks.current_page);
    return base + '?' + query.toString();
}

function send() {
    if (!canSend.value || busy.value) return;
    reply.operation_id = crypto.randomUUID();
    reply.post(base + '/' + props.selected.id + '/reply', { preserveScroll: true });
}

function closeTask() {
    close.patch(base + '/' + props.selected.id + '/close', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout>
        <Head :title="labels.ai_review_title" />
        <PageHeader :title="labels.ai_review_title">
            <template #actions>
                <Link href="/converse/inbox" class="ui-button"><AppIcon name="inbox" />{{ labels.inbox }}</Link>
            </template>
        </PageHeader>

        <p class="mb-4 text-sm text-muted">{{ review.description }}</p>
        <section class="overflow-hidden rounded-xl border border-line bg-surface" :aria-label="labels.ai_review_title">
            <nav class="flex flex-wrap gap-2 border-b border-line p-3" :aria-label="labels.ai_review_title">
                <Link :href="base" class="ui-button" :class="{ 'border-brand bg-selected text-brand': status === 'pending' }"
                    :aria-current="status === 'pending' ? 'page' : undefined">
                    {{ review.pending }} <span class="tabular-nums">{{ formatNumber(count, page.props.locale) }}</span>
                </Link>
                <Link :href="base + '?status=closed'" class="ui-button" :class="{ 'border-brand bg-selected text-brand': status === 'closed' }"
                    :aria-current="status === 'closed' ? 'page' : undefined">{{ review.closed }}</Link>
            </nav>
            <EmptyState v-if="!tasks.data.length" icon="inbox" :title="review[status === 'closed' ? 'empty_closed' : 'empty_pending']" />
            <ul v-else class="divide-y divide-line">
                <li v-for="task in tasks.data" :key="task.id">
                    <Link :href="taskUrl(task)" preserve-scroll class="grid gap-1 px-4 py-3 hover:bg-page motion-safe:transition-colors">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">{{ task.name || labels.unnamed_contact }} <span class="ms-1 text-xs font-normal text-muted">{{ labels[task.channel] }}</span></p>
                            <time :datetime="task.occurred_at" class="text-xs text-muted">{{ formatDhakaDateTime(task.occurred_at, page.props.locale) }}</time>
                        </div>
                        <p class="text-sm wrap-anywhere">{{ task.question }}</p>
                        <div class="flex flex-wrap gap-2 text-xs text-muted">
                            <span>{{ review[task.reason] }}</span>
                            <span v-if="task.notification_id && task.state === 'pending'" class="font-medium text-danger">{{ review.escalated }}</span>
                            <span v-if="task.state !== 'pending'" class="font-medium">{{ review[task.state] }}</span>
                            <span v-else-if="task.delivery_status || task.ai_delivery_status" class="font-medium">{{ labels['reply_' + (task.delivery_status || task.ai_delivery_status)] }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
            <div v-if="tasks.prev_page_url || tasks.next_page_url" class="flex justify-end gap-2 border-t border-line p-3">
                <Link v-if="tasks.prev_page_url" :href="tasks.prev_page_url" class="ui-button">{{ labels.previous }}</Link>
                <Link v-if="tasks.next_page_url" :href="tasks.next_page_url" class="ui-button">{{ labels.next }}</Link>
            </div>
        </section>

        <SlideOver v-if="selected" :title="selected.name || labels.unnamed_contact" :close-label="labels.close" :busy="busy"
            @close="router.visit(listUrl, { preserveScroll: true })">
            <div class="grid gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-muted">{{ labels[selected.channel] }} · {{ review[selected.state === 'pending' ? selected.reason : selected.state] }}</p>
                    <Link :href="'/converse/inbox/' + selected.conversation_id" class="ui-button">{{ labels.converse_open_conversation }}</Link>
                </div>
                <section class="rounded-lg border border-line bg-page p-3">
                    <h3 class="text-sm font-semibold">{{ review.question }}</h3>
                    <p class="mt-2 whitespace-pre-wrap wrap-anywhere text-sm">{{ selected.question }}</p>
                    <time :datetime="selected.occurred_at" class="mt-2 block text-xs text-muted">{{ formatDhakaDateTime(selected.occurred_at, page.props.locale) }}</time>
                </section>
                <details v-if="selected.history.length" class="rounded-lg border border-line p-3">
                    <summary class="cursor-pointer text-sm font-medium">{{ review.context }}</summary>
                    <ol class="mt-2 divide-y divide-line">
                        <li v-for="(message, index) in selected.history" :key="index" class="grid gap-1 py-2 text-sm">
                            <p class="text-xs text-muted">{{ message.direction === 'in' ? review.customer : review[message.sender_type] }}</p>
                            <p class="whitespace-pre-wrap wrap-anywhere">{{ message.body }}</p>
                        </li>
                    </ol>
                </details>
                <div v-if="selected.attempted_reply" class="rounded-lg border border-line p-3 text-sm">
                    <p class="font-semibold">{{ review.reply_body }} · {{ labels['reply_' + selected.delivery_status] || review[selected.state] }}</p>
                    <p class="mt-1 whitespace-pre-wrap wrap-anywhere">{{ selected.attempted_reply }}</p>
                </div>
                <p v-if="selected.blocked_reason" class="text-sm text-danger" role="status">{{ labels[selected.blocked_reason] }}</p>
                <p v-if="selected.state === 'pending' && selected.ai_delivery_status && selected.ai_delivery_status !== 'failed'" class="text-sm text-muted">
                    {{ labels['reply_' + selected.ai_delivery_status] || review.delivery }}
                </p>
                <form v-if="canSend" :id="formId" @submit.prevent="send">
                    <FormField v-slot="attrs" :label="review.reply_body" :error="reply.errors.body" required>
                        <textarea v-bind="attrs" v-model="reply.body" rows="5" maxlength="2000" class="block w-full rounded-lg border border-line bg-page p-3 text-sm"
                            :disabled="busy" />
                    </FormField>
                </form>
                <p v-else-if="reply.errors.body" class="text-sm text-danger" role="alert">{{ reply.errors.body }}</p>
                <p v-if="close.errors.body" class="text-sm text-danger" role="alert">{{ close.errors.body }}</p>
            </div>
            <template v-if="selected.state === 'pending'" #footer>
                <button type="button" class="ui-button" :disabled="busy" @click="closeTask">{{ review.close_task }}</button>
                <button v-if="canSend" type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="busy || !reply.body.trim()">
                    {{ reply.processing ? labels.reply_sending : review.send }}
                </button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
