<script setup>
import { Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    samples: { type: Object, required: true },
    cloudAvailable: Boolean,
    cloudProviders: { type: [Object, Array], required: true },
    ollamaAvailable: Boolean,
    sandboxAvailable: Boolean,
    knowledgeSamples: { type: Object, required: true },
    channels: { type: Array, required: true },
    collections: { type: Array, required: true },
});

const page = usePage();
const form = useHttp({
    mode: 'routing', language: 'en', provider_mode: props.ollamaAvailable || !props.cloudAvailable ? 'local' : 'cloud',
    cloud_provider: null, complex: false, sandbox: false, message: '', channel: null, collection: null,
});
const outcome = ref(null);
const failed = ref(false);
const draft = computed(() => outcome.value?.draft);
const knowledgeAvailable = computed(() => props.collections.length > 0);
const selectedChannel = computed(() => props.channels.find(({ id }) => id === form.channel));
const availableCollections = computed(() => selectedChannel.value
    ? props.collections.filter(({ id }) => selectedChannel.value.collections.includes(id))
    : props.collections);
const canRun = computed(() => {
    if (form.mode === 'knowledge') return form.message.trim().length >= 2 && availableCollections.value.length > 0;
    if (form.sandbox || form.provider_mode === 'local') return props.ollamaAvailable;
    return props.cloudAvailable;
});
const scope = computed(() => {
    if (!draft.value?.scope) return null;
    const { label, type } = draft.value.scope;
    return label || page.props.labels[type === 'none' ? 'playground_no_scope' : 'knowledge_chat_all_collections'];
});

function resetResult() {
    outcome.value = null;
    failed.value = false;
    form.clearErrors();
}

async function run() {
    resetResult();
    try {
        outcome.value = await form.post('/converse/playground');
    } catch {
        failed.value = true;
    }
}
</script>

<template>
    <Head :title="page.props.labels.playground" />
    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <PageHeader :title="page.props.labels.playground" />
            <Link v-if="page.props.canManageSettings" href="/settings#converse-ai" class="ui-button">{{ page.props.labels.settings_converse_ai }}</Link>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
            <form class="grid content-start gap-3 rounded-lg border border-line bg-surface p-4" @submit.prevent="run">
                <p class="text-sm text-muted">{{ page.props.labels.playground_help }}</p>
                <FormField v-if="knowledgeAvailable" v-slot="attrs" :label="page.props.labels.playground_test_mode">
                    <select v-bind="attrs" v-model="form.mode" class="ui-input" :disabled="form.processing" @change="resetResult">
                        <option value="routing">{{ page.props.labels.playground_model_routing }}</option>
                        <option value="knowledge">{{ page.props.labels.playground_knowledge_reply }}</option>
                    </select>
                </FormField>

                <template v-if="form.mode === 'knowledge'">
                    <p class="text-xs text-muted">{{ page.props.labels.playground_local_knowledge }}</p>
                    <FormField v-slot="attrs" :label="page.props.labels.playground_test_message" :error="form.errors.message" required>
                        <textarea v-bind="attrs" v-model="form.message" class="ui-input min-h-28" maxlength="2000" :disabled="form.processing" />
                    </FormField>
                    <FormField v-if="channels.length" v-slot="attrs" :label="page.props.labels.channel" :error="form.errors.channel">
                        <select v-bind="attrs" v-model="form.channel" class="ui-input" :disabled="form.processing" @change="form.collection = null">
                            <option :value="null">{{ page.props.labels.playground_default_routing }}</option>
                            <option v-for="channel in channels" :key="channel.id" :value="channel.id">{{ channel.name }} · {{ page.props.labels[channel.type] }}</option>
                        </select>
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.knowledge_chat_scope" :error="form.errors.collection">
                        <select v-bind="attrs" v-model="form.collection" class="ui-input" :disabled="form.processing || availableCollections.length === 0">
                            <option :value="null">{{ page.props.labels.knowledge_chat_automatic }}</option>
                            <option v-for="collection in availableCollections" :key="collection.id" :value="collection.id">{{ collection.name }}</option>
                        </select>
                    </FormField>
                    <p v-if="!availableCollections.length" class="text-xs text-muted">{{ page.props.labels.playground_collection_unavailable }}</p>
                </template>

                <template v-else>
                    <FormField v-slot="attrs" :label="page.props.labels.language_selector">
                        <select v-bind="attrs" v-model="form.language" class="ui-input" :disabled="form.processing">
                            <option value="en">English</option>
                            <option value="bn" lang="bn">বাংলা</option>
                            <option value="banglish">Banglish</option>
                        </select>
                    </FormField>
                    <p class="rounded-md bg-page p-3 text-sm">{{ (form.sandbox ? knowledgeSamples : samples)[form.language] }}</p>
                    <label v-if="sandboxAvailable" class="flex items-center gap-2 text-sm">
                        <input v-model="form.sandbox" type="checkbox" class="accent-brand" :disabled="form.processing">
                        {{ page.props.labels.ai_sandbox_knowledge }}
                    </label>
                    <FormField v-if="cloudAvailable && !form.sandbox" v-slot="attrs" :label="page.props.labels.playground_provider_mode">
                        <select v-bind="attrs" v-model="form.provider_mode" class="ui-input" :disabled="form.processing">
                            <option v-if="ollamaAvailable" value="local">{{ page.props.labels.playground_local }}</option>
                            <option value="configured">{{ page.props.labels.playground_configured_routing }}</option>
                            <option value="cloud">{{ page.props.labels.playground_cloud_only }}</option>
                        </select>
                    </FormField>
                    <FormField v-if="cloudAvailable && form.provider_mode === 'cloud' && !form.sandbox" v-slot="attrs" :label="page.props.labels.playground_cloud_provider">
                        <select v-bind="attrs" v-model="form.cloud_provider" class="ui-input" :disabled="form.processing">
                            <option :value="null">{{ page.props.labels.playground_cloud_all }}</option>
                            <option v-for="(name, id) in cloudProviders" :key="id" :value="id">{{ name }}</option>
                        </select>
                    </FormField>
                    <label v-if="cloudAvailable && !form.sandbox && form.provider_mode !== 'local'" class="flex items-center gap-2 text-sm">
                        <input v-model="form.complex" type="checkbox" class="accent-brand" :disabled="form.processing">
                        {{ page.props.labels.ai_test_advanced }}
                    </label>
                    <p v-if="!ollamaAvailable && !cloudAvailable" class="text-sm text-muted">{{ page.props.labels.playground_no_provider }}</p>
                </template>
                <button type="submit" class="ui-button ui-button-primary justify-self-start" :disabled="form.processing || !canRun">
                    {{ page.props.labels[form.processing ? 'playground_running' : 'playground_run'] }}
                </button>
            </form>

            <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="playground-result">
                <h2 id="playground-result" class="font-semibold">{{ page.props.labels.playground_result }}</h2>
                <div class="mt-3 grid gap-3 text-sm" role="status" aria-live="polite">
                    <template v-if="draft">
                        <dl class="flex flex-wrap gap-4 text-xs">
                            <div v-if="draft.decision">
                                <dt class="text-muted">{{ page.props.labels.playground_decision }}</dt>
                                <dd class="font-medium">{{ page.props.labels[draft.decision === 'send' ? 'knowledge_chat_send' : 'playground_would_handoff'] }}</dd>
                            </div>
                            <div v-if="draft.provider">
                                <dt class="text-muted">{{ page.props.labels.playground_provider }}</dt>
                                <dd class="font-medium">{{ [cloudProviders[draft.provider] || draft.provider, draft.model].filter(Boolean).join(' · ') }}<span v-if="draft.fallback"> · {{ page.props.labels.playground_fallback }}</span></dd>
                            </div>
                            <div v-if="scope">
                                <dt class="text-muted">{{ page.props.labels.knowledge_chat_scope }}</dt>
                                <dd class="font-medium">{{ scope }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted">{{ page.props.labels.playground_duration }}</dt>
                                <dd class="font-medium">{{ (outcome.duration_ms / 1000).toLocaleString(page.props.locale, { maximumFractionDigits: 2 }) }} {{ page.props.labels.playground_seconds }}</dd>
                            </div>
                        </dl>
                        <p v-for="(message, index) in draft.messages || [draft.text]" :key="index" class="rounded-md bg-page p-3 whitespace-pre-wrap wrap-anywhere">{{ message }}</p>
                        <div v-if="draft.sources?.length">
                            <p class="text-xs font-medium">{{ page.props.labels.playground_sources }}</p>
                            <ul class="list-disc ps-4 text-xs text-muted">
                                <li v-for="source in draft.sources" :key="source.id">{{ source.title }}</li>
                            </ul>
                        </div>
                        <p v-if="draft.attachment" class="text-xs text-muted">{{ page.props.labels.playground_attachment }} {{ draft.attachment }}</p>
                    </template>
                    <p v-else class="text-muted">
                        {{ page.props.labels[form.processing ? 'playground_running' : failed ? 'knowledge_chat_failed' : outcome ? 'playground_unavailable' : 'playground_result_empty'] }}
                    </p>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
