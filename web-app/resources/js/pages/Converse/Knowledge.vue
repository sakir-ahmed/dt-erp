<script setup>
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    collections: { type: Object, required: true },
    collection: { type: Object, default: null },
    entries: { type: Object, default: null },
    documents: { type: Array, default: () => [] },
    outboundAssets: { type: Array, default: () => [] },
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
const documentForm = useForm({ enabled: false, public: false, valid_from: '', valid_to: '', document: null });
const documentAction = useForm({});
const selectedDocument = ref(null);
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
    selectedDocument.value = null;
});

function editDocument(document) {
    selectedDocument.value = document.id;
    documentForm.enabled = Boolean(document.enabled);
    documentForm.public = Boolean(document.public);
    documentForm.valid_from = document.valid_from ?? '';
    documentForm.valid_to = document.valid_to ?? '';
    documentForm.reset('document');
    documentForm.clearErrors();
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
        onSuccess: () => { selectedDocument.value = null; },
        onFinish: () => documentForm.transform((data) => data),
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
const collectionForm = useForm({ name: '', enabled: false });
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
}

function save() {
    const base = '/converse/knowledge/collections';
    const path = panel.value === 'collection' ? base : `${base}/${props.collection.id}/entries`;
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
                <h2 class="border-b border-line px-3 py-2 text-sm font-semibold">{{ labels.collections }}</h2>
                <p v-if="!collections.data.length" class="p-3 text-sm text-muted">{{ labels.no_collections }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in collections.data" :key="row.id">
                        <Link :href="`/converse/knowledge?collection=${row.id}`" class="block px-3 py-2 text-sm hover:bg-page" :class="{ 'bg-selected': row.id === collection?.id }" :aria-current="row.id === collection?.id ? 'page' : undefined">{{ row.name }}</Link>
                    </li>
                </ul>
                <div v-if="collections.prev_page_url || collections.next_page_url" class="flex gap-2 p-3">
                    <Link v-if="collections.prev_page_url" :href="collections.prev_page_url" class="ui-button">{{ labels.previous }}</Link>
                    <Link v-if="collections.next_page_url" :href="collections.next_page_url" class="ui-button">{{ labels.next }}</Link>
                </div>
            </section>
            <section v-if="collection" class="rounded-lg border border-line bg-surface">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line p-3">
                    <h2 class="text-sm font-semibold">{{ collection.name }}</h2>
                    <div class="flex gap-2">
                        <button type="button" class="ui-button" @click="open('collection', collection)">{{ labels.edit_collection }}</button>
                        <button type="button" class="ui-button ui-button-primary" @click="open('entry')">{{ labels.add_entry }}</button>
                    </div>
                </header>
                <p v-if="!entries.data.length" class="p-3 text-sm text-muted">{{ labels.no_entries }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="entry in entries.data" :key="entry.id">
                        <button type="button" class="flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-page" @click="open('entry', entry)">
                            <span>{{ entry.title }}</span>
                            <span class="text-xs text-muted">{{ labels[`knowledge_${entry.kind}`] }} · {{ labels[entry.enabled ? 'enabled' : 'disabled'] }}</span>
                        </button>
                    </li>
                </ul>
                <div v-if="entries.prev_page_url || entries.next_page_url" class="flex gap-2 p-3">
                    <Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="ui-button">{{ labels.previous }}</Link>
                    <Link v-if="entries.next_page_url" :href="entries.next_page_url" class="ui-button">{{ labels.next }}</Link>
                </div>
            </section>
        </div>

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
                <li v-for="item in documents" :key="item.id" class="py-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="wrap-anywhere text-sm font-medium">{{ item.name }}</p>
                            <p class="text-xs text-muted">{{ labels[`knowledge_document_${item.status}`] }} · {{ labels[item.enabled ? 'enabled' : 'disabled'] }} · {{ labels[item.public ? 'knowledge_public' : 'knowledge_internal'] }} · {{ labels[`knowledge_document_language_${item.language}`] }}</p>
                            <p v-if="item.error" class="text-xs text-danger">{{ item.error }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="ui-button" @click="editDocument(item)">{{ labels.knowledge_edit_document }}</button>
                            <button type="button" class="ui-button" :disabled="documentAction.processing" @click="reindex(item.id)">{{ labels.knowledge_reindex }}</button>
                        </div>
                    </div>
                    <form v-if="selectedDocument === item.id" class="mt-2 flex flex-wrap items-end gap-3"
                        @submit.prevent="saveDocument(item.id)">
                        <label class="flex items-center gap-1 text-sm"><input v-model="documentForm.enabled" type="checkbox">{{ labels.enabled }}</label>
                        <label class="flex items-center gap-1 text-sm"><input v-model="documentForm.public" type="checkbox">{{ labels.knowledge_public }}</label>
                        <FormField v-slot="attrs" :label="labels.knowledge_valid_from" :error="documentForm.errors.valid_from"><input v-bind="attrs" v-model="documentForm.valid_from" type="date" class="ui-input"></FormField>
                        <FormField v-slot="attrs" :label="labels.knowledge_valid_to" :error="documentForm.errors.valid_to"><input v-bind="attrs" v-model="documentForm.valid_to" type="date" class="ui-input"></FormField>
                        <FormField v-slot="attrs" :label="labels.knowledge_replace_file" :error="documentForm.errors.document">
                            <input v-bind="attrs" type="file" accept=".md,.txt,.pdf,.docx,.csv,.xlsx,.json" class="ui-input"
                                @change="documentForm.document = $event.target.files[0] ?? null">
                        </FormField>
                        <button type="submit" class="ui-button" :disabled="documentForm.processing">{{ labels.save }}</button>
                    </form>
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

        <SlideOver v-if="panel" :title="panelTitle" :close-label="labels.close" :busy="form.processing" @close="panel = null">
            <form :id="formId" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField v-if="panel === 'collection'" v-slot="attrs" :label="labels.name" :error="form.errors.name" required class="sm:col-span-2">
                    <input v-bind="attrs" v-model="form.name" class="ui-input" maxlength="255" autofocus>
                </FormField>
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
