<script setup>
import { Head, Link, router, useForm, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ChannelBrandIcon from '../../components/ChannelBrandIcon.vue';
import ConverseContactAvatar from '../../components/ConverseContactAvatar.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SearchBar from '../../components/ui-kit/SearchBar.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { playConverseCue } from '../../lib/converseNotificationSounds';
import { formatBytes, formatDhakaDateTime } from '../../lib/format';

const props = defineProps({
    conversations: { type: Object, required: true },
    thread: { type: Object, default: null },
    messages: { type: Object, default: null },
    filters: { type: Object, required: true },
    channels: { type: Array, required: true },
    modes: { type: Array, required: true },
    statuses: { type: Array, required: true },
    canManageInbox: Boolean,
    catalogAttachments: { type: Array, default: () => [] },
    canDraftReply: Boolean,
    aiTrace: { type: Object, default: null },
    replyBlockedReason: { type: String, default: null },
});

const page = usePage();
const search = ref(props.filters.search);
const channel = ref(props.filters.channel);
const status = ref(props.filters.status);
const searching = ref(false);
const state = useForm({ mode: '', status: '', search: '', channel: '', filter_status: '' });

const reply = useForm({ body: '', operation_id: '', attachment: null, catalog_attachment_id: null });
const filePicker = ref(null);
const attachButton = ref(null);
const selectedAttachment = computed(() => reply.attachment?.name
    ?? props.catalogAttachments.find(({ id }) => id === reply.catalog_attachment_id)?.title
    ?? '');
const hasAttachment = computed(() => Boolean(reply.attachment || reply.catalog_attachment_id));
const retryForm = useForm({});
function retryAttachment(messageId) {
    retryForm.post(`/converse/inbox/${props.thread.id}/attachments/${messageId}/retry`, { preserveScroll: true });
}

function selectFile(event) {
    const file = event.target.files[0];
    if (file) {
        reply.attachment = file;
        reply.catalog_attachment_id = null;
        reply.clearErrors('attachment', 'catalog_attachment_id');
    }
    event.target.value = '';
}

function selectCatalog() {
    if (reply.catalog_attachment_id) reply.attachment = null;
    reply.clearErrors('attachment', 'catalog_attachment_id');
}

async function removeAttachment() {
    reply.attachment = null;
    reply.catalog_attachment_id = null;
    reply.clearErrors('attachment', 'catalog_attachment_id');
    await nextTick();
    attachButton.value?.focus();
}

const draft = useHttp({});
const draftUnavailable = ref(false);

async function draftReply() {
    const threadId = props.thread.id;
    const previousBody = reply.body;
    draftUnavailable.value = false;
    try {
        const response = await draft.post(`/converse/inbox/${threadId}/draft`);
        if (props.thread?.id === threadId && reply.body === previousBody) {
            reply.body = response.draft.body;
            if (response.draft.asset_id) {
                reply.catalog_attachment_id = response.draft.asset_id;
                selectCatalog();
            }
        }
    } catch {
        draftUnavailable.value = props.thread?.id === threadId;
    }
}
const poll = usePoll(3000, { only: ['conversations', 'thread', 'messages', 'replyBlockedReason', 'aiTrace'] }, { autoStart: false });

function syncPoll(busy = [searching.value, state.processing, reply.processing, retryForm.processing]) {
    if (busy.some(Boolean) || document.hidden) poll.stop();
    else poll.start();
}

watch([searching, () => state.processing, () => reply.processing, () => retryForm.processing], () => syncPoll(), { immediate: true });

function onVisibilityChange() {
    syncPoll();
}
onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibilityChange));

// Chat-style thread scrolling: stay pinned to the newest message unless the
// reader has scrolled up into history, and load older pages on demand.
const threadScroll = ref(null);
const pinnedToBottom = ref(true);
const loadingOlder = ref(false);
let restoreHeight = 0;
let lastInboundId = 0;

function threadMessages() {
    return props.messages?.data ?? [];
}

function latestInboundId() {
    const inbound = threadMessages().filter((message) => message.direction === 'in');
    return inbound.length ? inbound[0].id : 0;
}

function scrollToBottom() {
    const element = threadScroll.value;
    if (element) element.scrollTop = element.scrollHeight;
}

function onThreadScroll() {
    const element = threadScroll.value;
    if (!element) return;
    pinnedToBottom.value = element.scrollHeight - element.scrollTop - element.clientHeight < 120;
    if (element.scrollTop <= 48 && !loadingOlder.value) loadOlder();
}

function loadOlder() {
    const older = props.messages?.next_page_url;
    if (!older || loadingOlder.value) return;
    loadingOlder.value = true;
    restoreHeight = threadScroll.value?.scrollHeight ?? 0;
    router.visit(older, {
        only: ['messages'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loadingOlder.value = false; },
    });
}

watch(() => props.thread?.id, () => {
    lastInboundId = latestInboundId();
    pinnedToBottom.value = true;
    nextTick(scrollToBottom);
}, { immediate: true });

watch(() => props.messages, () => {
    const element = threadScroll.value;
    if (element && restoreHeight > 0) {
        // Keep the reader anchored while older history is prepended.
        element.scrollTop += element.scrollHeight - restoreHeight;
        restoreHeight = 0;
        return;
    }
    const fresh = latestInboundId();
    if (fresh > lastInboundId) {
        if (lastInboundId > 0) void playConverseCue('message_received');
        lastInboundId = fresh;
    }
    if (pinnedToBottom.value) nextTick(scrollToBottom);
});

watch([() => props.thread?.id, () => props.thread?.mode, () => props.thread?.status], ([id, mode, status], previous) => {
    if (id !== previous[0] || !state.isDirty || state.processing) {
        state.defaults({ mode: mode ?? '', status: status ?? '' });
        state.reset('mode', 'status');
        state.clearErrors();
    }
    if (id !== previous[0]) {
        draftUnavailable.value = false;
        reply.reset();
        reply.clearErrors();
    }
}, { immediate: true });

function sendReply() {
    reply.operation_id ||= crypto.randomUUID();
    reply.post(`/converse/inbox/${props.thread.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => {
            reply.reset();
            void playConverseCue('message_sent');
            pinnedToBottom.value = true;
            nextTick(scrollToBottom);
        },
    });
}

function saveState() {
    state.search = props.filters.search;
    state.channel = props.filters.channel;
    state.filter_status = props.filters.status;
    state.patch(`/converse/inbox/${props.thread.id}`, { preserveScroll: true });
}

watch(() => props.filters.channel, (value) => { channel.value = value; });
watch(() => props.filters.status, (value) => { status.value = value; });

function filter() {
    const term = search.value.trim();
    router.get('/converse/inbox', {
        ...(term && { search: term }),
        ...(channel.value && { channel: channel.value }),
        ...(status.value && { status: status.value }),
    }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { searching.value = true; },
        onFinish: () => { searching.value = false; },
    });
}

function contactLabel(contact) {
    const channel = page.props.labels[contact.channel] ?? contact.channel;
    return contact.name?.trim() || (contact.contact_suffix
        ? `${channel} •••• ${contact.contact_suffix}`
        : `${channel} ${page.props.labels.converse_contact} #${contact.id}`);
}

function isImageAttachment(message) {
    return typeof message.attachment_mime === 'string' && message.attachment_mime.startsWith('image/');
}

function isPdfAttachment(message) {
    return message.attachment_mime === 'application/pdf';
}

function threadUrl(id) {
    const params = new URLSearchParams();
    if (props.filters.search) params.set('search', props.filters.search);
    if (props.filters.channel) params.set('channel', props.filters.channel);
    if (props.filters.status) params.set('status', props.filters.status);
    const suffix = params.toString();

    return `/converse/inbox/${id}${suffix ? `?${suffix}` : ''}`;
}
</script>

<template>
    <Head :title="page.props.labels.inbox" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.inbox" />
        <div class="grid items-start gap-3 lg:grid-cols-[minmax(17rem,1fr)_minmax(0,2fr)]">
            <section class="min-w-0 overflow-hidden rounded-lg border border-line bg-surface" :aria-label="page.props.labels.inbox">
                <SearchBar v-model="search" :label="page.props.labels.search_conversations" :clear-label="page.props.labels.clear" :has-filter="Boolean(filters.search)" :busy="searching" @search="filter" />
                <div class="grid grid-cols-2 gap-2 border-b border-line p-3">
                    <select v-model="channel" class="ui-input" :aria-label="page.props.labels.channel" @change="filter">
                        <option value="">{{ page.props.labels.all_channels }}</option>
                        <option v-for="type in channels" :key="type" :value="type">{{ page.props.labels[type] }}</option>
                    </select>
                    <select v-model="status" class="ui-input" :aria-label="page.props.labels.thread_status" @change="filter">
                        <option value="">{{ page.props.labels.all_statuses }}</option>
                        <option v-for="value in statuses" :key="value" :value="value">{{ page.props.labels[`status_${value}`] }}</option>
                    </select>
                </div>
                <ul v-if="conversations.data.length" class="divide-y divide-line">
                    <li v-for="row in conversations.data" :key="row.id">
                        <Link :href="threadUrl(row.id)" :aria-current="thread?.id === row.id ? 'page' : undefined"
                            class="flex items-center gap-2 px-3 py-2 hover:bg-page focus-visible:outline-offset-[-2px]"
                            :class="{ 'bg-selected': thread?.id === row.id }">
                            <ConverseContactAvatar :name="row.name" :photo="row.sender_photo" />
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center justify-between gap-x-2">
                                    <span class="min-w-0 wrap-anywhere font-semibold">{{ contactLabel(row) }}</span>
                                    <span class="flex items-center gap-1">
                                        <ChannelBrandIcon :channel="row.channel" class="h-4 w-4 shrink-0" :title="page.props.labels[row.channel] ?? row.channel" />
                                        <span class="sr-only">{{ page.props.labels[row.channel] ?? row.channel }}</span>
                                    </span>
                                </span>
                                <span class="mt-1 block text-xs text-muted">{{ row.account }} · {{ formatDhakaDateTime(row.last_message_at, page.props.locale) }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
                <EmptyState v-else icon="inbox" :title="page.props.labels.no_conversations" />
                <div v-if="conversations.prev_page_url || conversations.next_page_url" class="flex justify-end gap-2 border-t border-line p-3">
                    <Link v-if="conversations.prev_page_url" :href="conversations.prev_page_url" class="ui-button">{{ page.props.labels.previous }}</Link>
                    <Link v-if="conversations.next_page_url" :href="conversations.next_page_url" class="ui-button">{{ page.props.labels.next }}</Link>
                </div>
            </section>
            <section v-if="thread" class="min-w-0 rounded-lg border border-line bg-surface" :aria-label="page.props.labels.conversation_history">
                <header class="border-b border-line px-3 py-2">
                    <div class="flex items-center gap-2">
                        <ConverseContactAvatar :name="thread.name" :photo="thread.sender_photo" />
                        <div class="min-w-0">
                            <h2 class="wrap-anywhere font-semibold">{{ contactLabel(thread) }}</h2>
                            <p class="flex items-center gap-1 text-xs text-muted">
                                <ChannelBrandIcon :channel="thread.channel" class="h-3.5 w-3.5 shrink-0" />
                                {{ thread.account }} · {{ page.props.labels[thread.channel] ?? thread.channel }}
                            </p>
                        </div>
                    </div>
                    <p v-if="!canManageInbox" class="mt-1 text-xs text-muted">{{ page.props.labels[`status_${thread.status}`] }} · {{ page.props.labels[`mode_${thread.mode}`] }}</p>
                    <details v-if="Object.keys(thread.memory ?? {}).length || thread.language_preference" class="mt-2 text-xs text-muted">
                        <summary class="cursor-pointer">{{ page.props.labels.ai_conversation_memory }}</summary>
                        <p v-if="thread.language_preference">{{ page.props.labels.ai_customer_language }} · {{ thread.language_preference }}</p>
                        <dl class="mt-1">
                            <div v-for="(item, key) in thread.memory" :key="key" class="flex gap-2">
                                <dt class="font-medium">{{ page.props.labels[`ai_memory_${key}`] }}</dt>
                                <dd class="wrap-anywhere">{{ item.text }}</dd>
                            </div>
                        </dl>
                    </details>
                    <p v-if="aiTrace" class="mt-1 text-xs text-muted">
                        {{ page.props.labels.ai_trace }} · {{ aiTrace.language }} · {{ aiTrace.decision }}<template v-if="aiTrace.model"> · {{ aiTrace.provider }} / {{ aiTrace.model }}</template>
                    </p>
                </header>
                <form v-if="canManageInbox" class="flex flex-wrap items-end gap-2 border-b border-line p-3" @submit.prevent="saveState">
                    <label class="text-xs font-medium">
                        {{ page.props.labels.thread_status }}
                        <select v-model="state.status" class="ui-input mt-1 block" :disabled="state.processing">
                            <option v-for="value in statuses" :key="value" :value="value">{{ page.props.labels[`status_${value}`] }}</option>
                        </select>
                    </label>
                    <label class="text-xs font-medium">
                        {{ page.props.labels.thread_mode }}
                        <select v-model="state.mode" class="ui-input mt-1 block" :disabled="state.processing">
                            <option v-for="value in modes" :key="value" :value="value">{{ page.props.labels[`mode_${value}`] }}</option>
                        </select>
                    </label>
                    <button type="submit" class="ui-button" :disabled="state.processing || (state.mode === thread.mode && state.status === thread.status)">
                        {{ page.props.labels.save }}
                    </button>
                    <p v-if="state.hasErrors" role="alert" class="w-full text-xs text-danger">{{ Object.values(state.errors)[0] }}</p>
                </form>
                <div ref="threadScroll" class="max-h-[26rem] overflow-y-auto overscroll-contain lg:max-h-[32rem]" @scroll.passive="onThreadScroll">
                    <div v-if="messages.next_page_url || messages.prev_page_url" class="flex justify-center gap-2 p-2">
                        <button v-if="messages.next_page_url" type="button" class="ui-button text-xs" :disabled="loadingOlder" @click="loadOlder">
                            {{ page.props.labels.older_messages }}
                        </button>
                        <Link v-if="messages.prev_page_url" :href="messages.prev_page_url" class="ui-button text-xs">{{ page.props.labels.newer_messages }}</Link>
                    </div>
                    <ol class="space-y-2 p-3">
                        <li v-for="message in [...messages.data].reverse()" :key="message.id" class="w-fit max-w-[90%] rounded-lg bg-page p-2.5"
                            :class="{ 'ms-auto bg-selected': message.direction === 'out' }">
                            <p v-if="message.body" class="whitespace-pre-wrap wrap-anywhere text-[13px]">{{ message.body }}</p>
                            <a v-if="message.attachment_name" class="mt-1 block"
                                :href="`/converse/inbox/${thread.id}/attachments/${message.id}`">
                                <img v-if="isImageAttachment(message)" class="max-h-64 max-w-full rounded-md border border-line object-contain"
                                    :src="`/converse/inbox/${thread.id}/attachments/${message.id}`" :alt="message.attachment_name" loading="lazy">
                                <span v-else class="flex items-center gap-2 rounded-md border border-line bg-surface p-2">
                                    <AppIcon :name="isPdfAttachment(message) ? 'file-pdf' : 'file'" class="shrink-0 text-brand" />
                                    <span class="min-w-0">
                                        <span class="block truncate text-[13px] font-medium">{{ message.attachment_name }}</span>
                                        <span v-if="message.attachment_bytes" class="text-xs text-muted">{{ formatBytes(message.attachment_bytes, page.props.locale) }}</span>
                                    </span>
                                </span>
                            </a>
                            <p class="mt-1 text-xs text-muted">
                                {{ formatDhakaDateTime(message.occurred_at, page.props.locale) }}
                                <span v-if="message.delivery_status"> · {{ page.props.labels[`reply_${message.delivery_status}`] }}</span>
                                <span v-if="message.delivery_status === 'partial' && message.attachment_status"> · {{ page.props.labels[`reply_${message.attachment_status}`] }}</span>
                                <button v-if="canManageInbox && !replyBlockedReason && message.delivery_status === 'partial' && message.attachment_status === 'failed'"
                                    type="button" class="ms-2 text-brand underline" :disabled="retryForm.processing" @click="retryAttachment(message.id)">
                                    {{ page.props.labels.converse_retry_file }}
                                </button>
                            </p>
                        </li>
                    </ol>
                </div>
                <form v-if="canManageInbox" class="grid gap-2 border-t border-line p-3" @submit.prevent="sendReply">
                    <p v-if="replyBlockedReason" class="text-xs text-muted">{{ page.props.labels[replyBlockedReason] }}</p>
                    <label v-else class="grid gap-1 text-xs font-medium">
                        {{ page.props.labels.reply_body }}
                        <textarea v-model="reply.body" maxlength="2000" rows="3" :required="!hasAttachment" :disabled="reply.processing" class="w-full rounded-md border border-line bg-page p-2 text-[13px]" />
                    </label>
                    <div v-if="hasAttachment" class="grid min-w-0 gap-1 rounded-md border border-line bg-page px-2 py-1.5">
                        <div class="flex min-w-0 items-center gap-2">
                            <AppIcon name="paperclip" class="shrink-0 text-muted" />
                            <span class="min-w-0 flex-1 truncate text-xs font-medium" :title="selectedAttachment">{{ selectedAttachment }}</span>
                            <button type="button" class="ui-button ui-button-icon shrink-0" :disabled="reply.processing"
                                :aria-label="page.props.labels.converse_remove_attachment" @click="removeAttachment">
                                <AppIcon name="close" />
                            </button>
                        </div>
                        <progress v-if="reply.progress" class="h-1.5 w-full accent-brand" max="100" :value="reply.progress.percentage"
                            :aria-label="page.props.labels.converse_upload_progress" />
                    </div>
                    <p v-if="reply.hasErrors || retryForm.hasErrors" role="alert" class="text-xs text-danger">
                        {{ Object.values(reply.errors)[0] || Object.values(retryForm.errors)[0] }}
                    </p>
                    <p v-if="draftUnavailable" role="status" class="text-xs text-muted">{{ page.props.labels.playground_unavailable }}</p>
                    <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <input ref="filePicker" type="file" accept=".pdf,.jpeg,.jpg,.png" hidden
                                :disabled="reply.processing || Boolean(replyBlockedReason)" @change="selectFile">
                            <button ref="attachButton" type="button" class="ui-button ui-button-icon"
                                :aria-label="page.props.labels.converse_attach_file" :title="page.props.labels.converse_attach_file"
                                :disabled="reply.processing || Boolean(replyBlockedReason)" @click="filePicker?.click()">
                                <AppIcon name="paperclip" />
                            </button>
                            <select v-if="catalogAttachments.length" v-model="reply.catalog_attachment_id" class="ui-input max-w-40 text-xs"
                                :aria-label="page.props.labels.converse_approved_asset" :disabled="reply.processing || Boolean(replyBlockedReason)"
                                @change="selectCatalog">
                                <option :value="null">{{ page.props.labels.converse_approved_asset }}</option>
                                <option v-for="asset in catalogAttachments" :key="asset.id" :value="asset.id">{{ asset.title }}</option>
                            </select>
                            <button v-if="canDraftReply && !replyBlockedReason" type="button" class="ui-button" :disabled="draft.processing || reply.processing" @click="draftReply">
                                {{ page.props.labels.ai_draft }}
                            </button>
                        </div>
                        <button v-if="!replyBlockedReason" type="submit" class="ui-button ui-button-primary ms-auto shrink-0"
                            :disabled="reply.processing || (!reply.body.trim() && !hasAttachment)">
                            {{ page.props.labels.reply_send }}
                        </button>
                    </div>
                </form>
            </section>
            <div v-else class="rounded-lg border border-line bg-surface">
                <EmptyState icon="inbox" :title="page.props.labels.select_conversation" />
            </div>
        </div>
    </AdminLayout>
</template>
