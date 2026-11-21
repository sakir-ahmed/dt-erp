<script setup>
import { Head, Link, useHttp, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { formatDhakaDateTime } from '../../lib/format';

const props = defineProps({
    collections: { type: Object, required: true },
    collection: { type: Object, default: null },
    entries: { type: Object, default: null },
    documents: { type: Array, default: () => [] },
    outboundAssets: { type: Array, default: () => [] },
    corrections: { type: Array, default: () => [] },
    subjects: { type: Array, required: true },
    kinds: { type: Array, required: true },
});
const page = usePage();
const labels = computed(() => page.props.labels);
const fields = ['title', 'body', 'valid_from', 'valid_to'];
const formId = useId();
const panel = ref(null);
const editing = ref(null);
const assetUpload = useForm({ title: '', attachment: null });
const assetApproval = useForm({ approved: false });
const assetUploadKey = ref(0);
const assetsPath = computed(() => `/converse/knowledge/collections/${props.collection.id}/outbound-assets`);

function uploadAsset() {
    assetUpload.post(assetsPath.value, { preserveScroll: true, onSuccess: () => {
        assetUpload.reset();
        assetUploadKey.value++;
    } });
}

function toggleAsset(asset) {
    assetApproval.approved = !asset.approved;
    assetApproval.patch(`${assetsPath.value}/${asset.id}`, { preserveScroll: true });
}

const uploadForm = useForm({ document: null });
const documentForm = useForm({ enabled: false, public: false, priority: 0, valid_from: '', valid_to: '', document: null });
const documentAction = useForm({});
const documentPanel = ref(false);
const editingDocument = ref(null);
const documentsPath = computed(() => `/converse/knowledge/collections/${props.collection.id}/documents`);
const poll = usePoll(3000, { only: ['documents'] }, { autoStart: false });

watch(() => props.documents.some((item) => item.status === 'queued'), (queued) => {
    if (queued) poll.start();
    else poll.stop();
}, { immediate: true });

watch(() => props.collection?.id, () => {
    assetUpload.reset();
    assetUpload.clearErrors();
    assetUploadKey.value++;
    uploadForm.reset();
    uploadForm.clearErrors();
    documentPanel.value = false;
});

function documentStatusClass(item) {
    if (item.status === 'indexed') return 'bg-success/10 text-success';
    if (item.status === 'failed') return 'bg-danger/10 text-danger';
    return 'bg-page text-muted';
}

function editDocument(document) {
    editingDocument.value = document;
    documentForm.enabled = Boolean(document.enabled);
    documentForm.public = Boolean(document.public);
    documentForm.priority = document.priority ?? 0;
    documentForm.valid_from = document.valid_from ?? '';
    documentForm.valid_to = document.valid_to ?? '';
    documentForm.reset('document');
    documentForm.clearErrors();
    documentPanel.value = true;
}

function previewDocument(document) {
    window.open(`${documentsPath.value}/${document.id}/preview`, '_blank', 'noopener');
}

function uploadDocument(event) {
    const fileInput = event.currentTarget.elements.namedItem('document');
    uploadForm.post(documentsPath.value, {
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('document');
            fileInput.value = '';
        },
    });
}

function saveDocument(id) {
    const url = `${documentsPath.value}/${id}`;
    const options = {
        preserveScroll: true,
        onSuccess: () => { documentPanel.value = false; },
    };

    if (documentForm.document) {
        documentForm.transform((data) => ({ ...data, _method: 'PATCH' })).post(url, options);
    } else {
        documentForm.patch(url, options);
    }
}

function reindex(id = null) {
    documentAction.post(`${documentsPath.value}/reindex${id ? `/${id}` : ''}`, { preserveScroll: true });
}
const collectionForm = useForm({ name: '', enabled: false, keywords: '' });
const entryForm = useForm({
    kind: 'product', language: 'en', title: '', body: '', enabled: false, public: false,
    subject_id: '', amount_type: 'fixed', amount: '', currency: 'BDT', valid_from: '', valid_to: '',
});
const form = computed(() => panel.value === 'collection' ? collectionForm : entryForm);
const commercial = computed(() => ['price', 'offer'].includes(entryForm.kind));
const types = computed(() => entryForm.kind === 'price' ? ['fixed', 'from', 'monthly', 'yearly', 'one_time', 'quote'] : ['fixed', 'percent']);
const panelTitle = computed(() => labels.value[`${editing.value ? 'edit' : 'add'}_${panel.value}`]);

function open(kind, record = null) {
    panel.value = kind;
    editing.value = record?.id ?? null;
    form.value.reset();
    form.value.clearErrors();
    if (record) {
        for (const key of Object.keys(form.value.data())) {
            if (record[key] !== null) form.value[key] = typeof form.value[key] === 'boolean' ? Boolean(record[key]) : record[key];
        }
    }
    if (kind === 'collection') {
        collectionForm.keywords = Array.isArray(record?.keywords) ? record.keywords.join(', ') : '';
    }
}

function saveCollectionForm() {
    collectionForm.transform((data) => ({
        name: data.name, enabled: data.enabled,
        keywords: data.keywords.split(',').map((keyword) => keyword.trim()).filter(Boolean),
    }));
    collectionForm.submit(editing.value ? 'patch' : 'post', editing.value ? `/converse/knowledge/collections/${editing.value}` : '/converse/knowledge/collections', {
        preserveScroll: true,
        onSuccess: () => { panel.value = null; },
    });
}

// Test chat: ask against one collection or all collections and inspect the
// grounded answer; unexpected replies become trained corrections.
const chat = useHttp({ collection: null, message: '' });
const chatScope = ref('all');
const chatMessage = ref('');
const chatBusy = ref(false);
const chatTranscript = ref([]);
const chatError = ref(false);
const chatBox = ref(null);

async function askChat() {
    const message = chatMessage.value.trim();
    if (!message || chatBusy.value) return;
    chatBusy.value = true;
    chatError.value = false;
    chatTranscript.value.push({ role: 'user', body: message, at: new Date().toISOString() });
    chatMessage.value = '';
    try {
        const response = await chat.post('/converse/knowledge/chat', {
            message,
            ...(chatScope.value !== 'all' ? { collection: Number(chatScope.value) } : {}),
        });
        chatTranscript.value.push({ role: 'ai', at: new Date().toISOString(), ...response.reply });
    } catch {
        chatError.value = true;
    } finally {
        chatBusy.value = false;
        await nextTick(() => chatBox.value?.scrollTo({ top: chatBox.value.scrollHeight }));
    }
}

const correctionForm = useForm({ collection_id: null, question: '', answer: '' });
const correctionPanel = ref(false);
const correctionAction = useForm({});

function trainCorrection(question) {
    correctionForm.reset();
    correctionForm.clearErrors();
    correctionForm.question = question;
    correctionForm.collection_id = props.collection?.id ?? null;
    correctionPanel.value = true;
}

function saveCorrection() {
    correctionForm.post('/converse/knowledge/corrections', {
        preserveScroll: true,
        onSuccess: () => { correctionPanel.value = false; },
    });
}

function toggleCorrection(item) {
    correctionAction.enabled = !item.enabled;
    correctionAction.patch(`/converse/knowledge/corrections/${item.id}`, { preserveScroll: true });
}

function deleteCorrection(item) {
    correctionAction.delete(`/converse/knowledge/corrections/${item.id}`, { preserveScroll: true });
}

function save() {
    if (panel.value === 'collection') {
        saveCollectionForm();

        return;
    }
    const path = `/converse/knowledge/collections/${props.collection.id}/entries`;
    form.value.submit(editing.value ? 'patch' : 'post', editing.value ? `${path}/${editing.value}` : path, {
        preserveScroll: true,
        onSuccess: () => { panel.value = null; },
    });
}
</script>

<template>
    <Head :title="labels.knowledge" />
    <AdminLayout>
        <PageHeader :title="labels.knowledge">
            <template #actions><button type="button" class="ui-button ui-button-primary" @click="open('collection')">{{ labels.add_collection }}</button></template>
        </PageHeader>
        <div class="grid items-start gap-3 lg:grid-cols-[minmax(16rem,1fr)_minmax(0,2fr)]">
            <section class="rounded-lg border border-line bg-surface" :aria-label="labels.collections">
                <h2 class="flex items-center gap-2 border-b border-line px-3 py-2 text-sm font-semibold"><AppIcon name="book" class="text-muted" />{{ labels.collections }}</h2>
                <p v-if="!collections.data.length" class="p-3 text-sm text-muted">{{ labels.no_collections }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in collections.data" :key="row.id">
                        <Link :href="`/converse/knowledge?collection=${row.id}`" class="block px-3 py-2 text-sm hover:bg-page" :class="{ 'bg-selected': row.id === collection?.id }" :aria-current="row.id === collection?.id ? 'page' : undefined">
                            {{ row.name }}
                            <span v-if="Array.isArray(row.keywords) && row.keywords.length" class="mt-0.5 block text-xs text-muted">{{ row.keywords.join(' · ') }}</span>
                        </Link>
                    </li>
                </ul>
                <div v-if="collections.prev_page_url || collections.next_page_url" class="flex gap-2 p-3">
                    <Link v-if="collections.prev_page_url" :href="collections.prev_page_url" class="ui-button">{{ labels.previous }}</Link>
                    <Link v-if="collections.next_page_url" :href="collections.next_page_url" class="ui-button">{{ labels.next }}</Link>
                </div>
            </section>
            <section v-if="collection" class="rounded-lg border border-line bg-surface">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line p-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold">{{ collection.name }}</h2>
                        <p v-if="Array.isArray(collection.keywords) && collection.keywords.length" class="text-xs text-muted">{{ collection.keywords.join(' · ') }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="ui-button" @click="open('collection', collection)">{{ labels.edit_collection }}</button>
                        <button type="button" class="ui-button ui-button-primary" @click="open('entry')">{{ labels.add_entry }}</button>
                    </div>
                </header>
                <EmptyState v-if="!entries.data.length" icon="book" :title="labels.no_entries" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="entry in entries.data" :key="entry.id">
                        <button type="button" class="flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-page" @click="open('entry', entry)">
                            <span>{{ entry.title }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="entry.enabled ? 'bg-success/10 text-success' : 'bg-page text-muted'">{{ labels[entry.enabled ? 'enabled' : 'disabled'] }}</span>
                        </button>
                    </li>
                </ul>
                <div v-if="entries.prev_page_url || entries.next_page_url" class="flex gap-2 p-3">
                    <Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="ui-button">{{ labels.previous }}</Link>
                    <Link v-if="entries.next_page_url" :href="entries.next_page_url" class="ui-button">{{ labels.next }}</Link>
                </div>
            </section>
        </div>

        <section class="mt-3 rounded-lg border border-line bg-surface" aria-labelledby="knowledge-chat-title">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line p-3">
                <div>
                    <h2 id="knowledge-chat-title" class="flex items-center gap-2 text-sm font-semibold"><AppIcon name="play" class="text-muted" />{{ labels.knowledge_chat_title }}</h2>
                    <p class="mt-0.5 text-xs text-muted">{{ labels.knowledge_chat_help }}</p>
                </div>
                <label class="text-xs font-medium">
                    {{ labels.knowledge_chat_scope }}
                    <select v-model="chatScope" class="ui-input mt-1 block" :disabled="chatBusy">
                        <option value="all">{{ labels.knowledge_chat_all_collections }}</option>
                        <option v-for="row in collections.data" :key="row.id" :value="String(row.id)">{{ row.name }}</option>
                    </select>
                </label>
            </header>
            <div ref="chatBox" class="max-h-96 space-y-2 overflow-y-auto p-3">
                <EmptyState v-if="!chatTranscript.length" icon="play" :title="labels.knowledge_chat_empty" />
                <template v-for="(item, index) in chatTranscript" :key="index">
                    <p v-if="item.role === 'user'" class="ms-auto w-fit max-w-[85%] whitespace-pre-wrap wrap-anywhere rounded-lg bg-selected p-2.5 text-[13px]">{{ item.body }}</p>
                    <div v-else class="w-fit max-w-[85%] rounded-lg bg-page p-2.5">
                        <p class="whitespace-pre-wrap wrap-anywhere text-[13px]">{{ item.body }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted">
                            <span class="rounded-full px-2 py-0.5" :class="item.decision === 'send' ? 'bg-success/10 text-success' : 'bg-page'">{{ labels[`knowledge_chat_${item.decision}`] }}</span>
                            <span v-if="item.correction" class="rounded-full bg-brand/10 px-2 py-0.5 text-brand">{{ labels.knowledge_correction_badge }}</span>
                            <span v-else-if="item.provider">{{ item.provider }}<template v-if="item.model"> · {{ item.model }}</template></span>
                            <span v-for="source in item.sources" :key="source.id" class="rounded-full bg-page px-2 py-0.5">{{ source.title }}</span>
                            <span>· {{ formatDhakaDateTime(item.at, page.props.locale) }}</span>
                        </p>
                        <button v-if="item.decision !== 'send' || !item.correction" type="button" class="ui-button mt-2 text-xs" @click="trainCorrection(chatTranscript[index - 1]?.body ?? '')">
                            {{ labels.knowledge_train_answer }}
                        </button>
                    </div>
                </template>
            </div>
            <form class="grid gap-2 border-t border-line p-3" @submit.prevent="askChat">
                <p v-if="chatError" role="alert" class="text-xs text-danger">{{ labels.knowledge_chat_failed }}</p>
                <textarea v-model="chatMessage" rows="2" maxlength="2000" class="w-full rounded-md border border-line bg-page p-2 text-[13px]" :placeholder="labels.knowledge_chat_placeholder" :disabled="chatBusy" />
                <button type="submit" class="ui-button ui-button-primary justify-self-end" :disabled="chatBusy || !chatMessage.trim()">{{ labels.knowledge_chat_ask }}</button>
            </form>
        </section>

        <section class="mt-3 rounded-lg border border-line bg-surface p-3" aria-labelledby="knowledge-corrections-title">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="knowledge-corrections-title" class="flex items-center gap-2 text-sm font-semibold"><AppIcon name="edit" class="text-muted" />{{ labels.knowledge_corrections_title }}</h2>
                    <p class="mt-0.5 text-xs text-muted">{{ labels.knowledge_corrections_help }}</p>
                </div>
                <button type="button" class="ui-button" @click="trainCorrection('')">{{ labels.knowledge_add_correction }}</button>
            </div>
            <EmptyState v-if="!corrections.length" icon="book" :title="labels.knowledge_no_corrections" />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in corrections" :key="item.id" class="flex flex-wrap items-start justify-between gap-2 py-2">
                    <div class="min-w-0">
                        <p class="wrap-anywhere text-sm font-medium">{{ item.question }}</p>
                        <p class="wrap-anywhere mt-0.5 text-xs text-muted">{{ item.answer }}</p>
                        <p class="mt-1 text-xs text-muted">
                            <span class="rounded-full px-2 py-0.5" :class="item.enabled ? 'bg-success/10 text-success' : 'bg-page'">{{ labels[item.enabled ? 'enabled' : 'disabled'] }}</span>
                            · {{ item.collection_id ? (collections.data.find(({ id }) => id === item.collection_id)?.name ?? labels.knowledge_collection_scope) : labels.knowledge_correction_global }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button type="button" class="ui-button" :disabled="correctionAction.processing" @click="toggleCorrection(item)">{{ labels[item.enabled ? 'disable' : 'enable'] }}</button>
                        <button type="button" class="ui-button" :disabled="correctionAction.processing" @click="deleteCorrection(item)">{{ labels.delete }}</button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="collection" class="mt-3 rounded-lg border border-line bg-surface p-3" aria-labelledby="knowledge-documents-title">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="knowledge-documents-title" class="text-sm font-semibold">{{ labels.knowledge_documents }}</h2>
                <button v-if="documents.length" type="button" class="ui-button" :disabled="documentAction.processing" @click="reindex()">{{ labels.knowledge_reindex_all }}</button>
            </div>

            <form class="flex flex-wrap items-end gap-3" @submit.prevent="uploadDocument">
                <FormField v-slot="attrs" :label="labels.knowledge_upload_file" :error="uploadForm.errors.document" required>
                    <input v-bind="attrs" type="file" name="document" accept=".md,.txt,.pdf,.docx,.csv,.xlsx,.json" class="ui-input"
                        @change="uploadForm.document = $event.target.files[0] ?? null">
                </FormField>
                <button type="submit" class="ui-button ui-button-primary" :disabled="uploadForm.processing || !uploadForm.document">{{ labels.knowledge_upload }}</button>
            </form>

            <ul v-if="documents.length" class="mt-3 divide-y divide-line border-t border-line">
                <li v-for="item in documents" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="wrap-anywhere text-sm font-medium">{{ item.name }}</span>
                            <span v-if="item.priority > 0" class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-semibold text-brand">#{{ item.priority }}</span>
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="rounded-full px-2 py-0.5" :class="documentStatusClass(item)">{{ labels[`knowledge_document_${item.status}`] }}</span>
                            <span class="rounded-full px-2 py-0.5" :class="item.enabled ? 'bg-success/10 text-success' : 'bg-page text-muted'">{{ labels[item.enabled ? 'enabled' : 'disabled'] }}</span>
                            <span class="rounded-full bg-page px-2 py-0.5 text-muted">{{ labels[item.public ? 'knowledge_public' : 'knowledge_internal'] }}</span>
                            <span class="rounded-full bg-page px-2 py-0.5 text-muted">{{ labels[`knowledge_document_language_${item.language}`] }}</span>
                        </p>
                        <p v-if="item.error" class="mt-1 text-xs text-danger">{{ item.error }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button type="button" class="ui-button" @click="previewDocument(item)">{{ labels.knowledge_preview_document }}</button>
                        <button type="button" class="ui-button" @click="editDocument(item)">{{ labels.knowledge_edit_document }}</button>
                        <button type="button" class="ui-button" :disabled="documentAction.processing" @click="reindex(item.id)">{{ labels.knowledge_reindex }}</button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="collection" class="mt-3 rounded-lg border border-line bg-surface p-3" aria-labelledby="outbound-assets-title">
            <h2 id="outbound-assets-title" class="mb-2 text-sm font-semibold">{{ labels.converse_asset_catalog }}</h2>
            <p class="mb-3 text-xs text-muted">{{ labels.converse_asset_catalog_help }}</p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="uploadAsset">
                <FormField v-slot="attrs" :label="labels.knowledge_title" :error="assetUpload.errors.title" required>
                    <input v-bind="attrs" v-model="assetUpload.title" maxlength="160" class="ui-input">
                </FormField>
                <FormField v-slot="attrs" :label="labels.converse_attach_file" :error="assetUpload.errors.attachment" required>
                    <input :key="assetUploadKey" v-bind="attrs" type="file" accept=".pdf,.jpeg,.jpg,.png" class="ui-input"
                        @change="assetUpload.attachment = $event.target.files[0] ?? null">
                </FormField>
                <button type="submit" class="ui-button ui-button-primary" :disabled="assetUpload.processing || !assetUpload.attachment">{{ labels.converse_asset_upload }}</button>
            </form>
            <ul v-if="outboundAssets.length" class="mt-3 divide-y divide-line border-t border-line">
                <li v-for="asset in outboundAssets" :key="asset.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div class="min-w-0">
                        <p class="wrap-anywhere text-sm font-medium">{{ asset.title }}</p>
                        <p class="text-xs text-muted">{{ asset.name }} · {{ labels[asset.approved ? 'converse_asset_approved' : 'converse_asset_unapproved'] }}</p>
                    </div>
                    <button type="button" class="ui-button" :disabled="assetApproval.processing" @click="toggleAsset(asset)">
                        {{ labels[asset.approved ? 'converse_asset_revoke' : 'converse_asset_approve'] }}
                    </button>
                </li>
            </ul>
        </section>

        <SlideOver v-if="correctionPanel" :title="labels.knowledge_train_title" :close-label="labels.close" :busy="correctionForm.processing" @close="correctionPanel = false">
            <form :id="`${formId}-correction`" class="grid gap-3" @submit.prevent="saveCorrection">
                <p class="text-xs text-muted">{{ labels.knowledge_train_help }}</p>
                <FormField v-slot="attrs" :label="labels.knowledge_correction_question" :error="correctionForm.errors.question" required>
                    <textarea v-bind="attrs" v-model="correctionForm.question" rows="2" maxlength="500" class="w-full rounded-md border border-line bg-page p-2 text-sm" />
                </FormField>
                <FormField v-slot="attrs" :label="labels.knowledge_correction_answer" :error="correctionForm.errors.answer" required>
                    <textarea v-bind="attrs" v-model="correctionForm.answer" rows="4" maxlength="2000" class="w-full rounded-md border border-line bg-page p-2 text-sm" />
                </FormField>
                <FormField v-slot="attrs" :label="labels.knowledge_correction_scope">
                    <select v-bind="attrs" v-model="correctionForm.collection_id" class="ui-input">
                        <option :value="null">{{ labels.knowledge_correction_global }}</option>
                        <option v-for="row in collections.data" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                </FormField>
            </form>
            <template #footer>
                <button type="button" class="ui-button" :disabled="correctionForm.processing" @click="correctionPanel = false">{{ labels.cancel }}</button>
                <button type="submit" :form="`${formId}-correction`" class="ui-button ui-button-primary" :disabled="correctionForm.processing">{{ labels.save }}</button>
            </template>
        </SlideOver>

        <SlideOver v-if="documentPanel && editingDocument" :title="labels.knowledge_edit_document" :close-label="labels.close" :busy="documentForm.processing" @close="documentPanel = false">
            <form :id="`${formId}-document`" class="grid gap-3" @submit.prevent="saveDocument(editingDocument.id)">
                <p class="wrap-anywhere text-sm text-muted">{{ editingDocument.name }}</p>
                <FormField v-slot="attrs" :label="labels.knowledge_priority" :error="documentForm.errors.priority">
                    <input v-bind="attrs" v-model="documentForm.priority" type="number" min="0" max="255" step="1" class="ui-input">
                </FormField>
                <p class="-mt-2 text-xs text-muted">{{ labels.knowledge_priority_help }}</p>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="documentForm.enabled" type="checkbox" class="accent-brand">
                    {{ labels.enabled }}
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="documentForm.public" type="checkbox" class="accent-brand">
                    {{ labels.knowledge_public }}
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField v-slot="attrs" :label="labels.knowledge_valid_from" :error="documentForm.errors.valid_from">
                        <input v-bind="attrs" v-model="documentForm.valid_from" type="date" class="ui-input">
                    </FormField>
                    <FormField v-slot="attrs" :label="labels.knowledge_valid_to" :error="documentForm.errors.valid_to">
                        <input v-bind="attrs" v-model="documentForm.valid_to" type="date" class="ui-input">
                    </FormField>
                </div>
                <FormField v-slot="attrs" :label="labels.knowledge_replace_file" :error="documentForm.errors.document">
                    <input v-bind="attrs" type="file" accept=".md,.txt,.pdf,.docx,.csv,.xlsx,.json" class="ui-input"
                        @change="documentForm.document = $event.target.files[0] ?? null">
                </FormField>
            </form>
            <template #footer>
                <button type="button" class="ui-button" :disabled="documentForm.processing" @click="documentPanel = false">{{ labels.cancel }}</button>
                <button type="submit" :form="`${formId}-document`" class="ui-button ui-button-primary" :disabled="documentForm.processing">{{ labels.save }}</button>
            </template>
        </SlideOver>

        <SlideOver v-if="panel" :title="panelTitle" :close-label="labels.close" :busy="form.processing" @close="panel = null">
            <form :id="formId" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField v-if="panel === 'collection'" v-slot="attrs" :label="labels.name" :error="form.errors.name" required class="sm:col-span-2">
                    <input v-bind="attrs" v-model="form.name" class="ui-input" maxlength="255" autofocus>
                </FormField>
                <FormField v-if="panel === 'collection'" v-slot="attrs" :label="labels.knowledge_keywords" :error="form.errors.keywords" class="sm:col-span-2">
                    <input v-bind="attrs" v-model="collectionForm.keywords" class="ui-input" maxlength="1200" :placeholder="labels.knowledge_keywords_placeholder">
                </FormField>
                <p v-if="panel === 'collection'" class="-mt-2 text-xs text-muted sm:col-span-2">{{ labels.knowledge_keywords_help }}</p>
                <template v-else>
                    <FormField v-slot="attrs" :label="labels.knowledge_kind" :error="form.errors.kind" required>
                        <select v-bind="attrs" v-model="entryForm.kind" class="ui-input" :disabled="editing !== null" @change="entryForm.amount_type = 'fixed'">
                            <option v-for="kind in kinds" :key="kind" :value="kind">{{ labels[`knowledge_${kind}`] }}</option>
                        </select>
                    </FormField>
                    <FormField v-slot="attrs" :label="labels.language_selector" :error="form.errors.language" required>
                        <select v-bind="attrs" v-model="entryForm.language" class="ui-input"><option value="en">English</option><option value="bn">বাংলা</option><option value="banglish">Banglish</option></select>
                    </FormField>
                    <FormField v-for="field in fields" :key="field" v-slot="attrs" :label="labels[`knowledge_${field}`]" :error="form.errors[field]" :required="field === 'title'" :class="{ 'sm:col-span-2': ['title','body'].includes(field) }">
                        <textarea v-if="field === 'body'" v-bind="attrs" v-model="entryForm[field]" rows="4" maxlength="12000" class="w-full rounded-md border border-line bg-surface p-2 text-sm" />
                        <input v-else v-bind="attrs" v-model="entryForm[field]" class="ui-input" :type="field === 'title' ? 'text' : 'date'" :maxlength="field === 'title' ? 255 : undefined">
                    </FormField>
                    <template v-if="commercial">
                        <FormField v-slot="attrs" :label="labels.knowledge_subject" :error="form.errors.subject_id" required>
                            <select v-bind="attrs" v-model="entryForm.subject_id" class="ui-input">
                                <option value="" disabled>{{ labels.knowledge_subject }}</option>
                                <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.title }}</option>
                            </select>
                        </FormField>
                        <FormField v-slot="attrs" :label="labels.knowledge_amount_type" :error="form.errors.amount_type" required>
                            <select v-bind="attrs" v-model="entryForm.amount_type" class="ui-input"><option v-for="type in types" :key="type" :value="type">{{ labels[`amount_${type}`] }}</option></select>
                        </FormField>
                        <FormField v-if="entryForm.amount_type !== 'quote'" v-slot="attrs" :label="labels.knowledge_amount" :error="form.errors.amount" required>
                            <input v-bind="attrs" v-model="entryForm.amount" class="ui-input" inputmode="decimal">
                        </FormField>
                        <FormField v-if="!['quote', 'percent'].includes(entryForm.amount_type)" v-slot="attrs" :label="labels.knowledge_currency" :error="form.errors.currency" required>
                            <input v-bind="attrs" v-model="entryForm.currency" class="ui-input uppercase" maxlength="3" pattern="[A-Z]{3}">
                        </FormField>
                    </template>
                    <label class="flex items-center gap-2 text-sm"><input v-model="entryForm.public" type="checkbox" class="accent-brand">{{ labels.knowledge_public }}</label>
                </template>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" type="checkbox" class="accent-brand">{{ labels.enabled }}</label>
            </form>
            <template #footer>
                <button type="button" class="ui-button" :disabled="form.processing" @click="panel = null">{{ labels.cancel }}</button>
                <button type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="form.processing">{{ labels.save }}</button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
