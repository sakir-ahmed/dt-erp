<script setup>
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    samples: { type: Object, required: true },
    cloudAvailable: Boolean,
    outboundEnabled: Boolean,
    sandboxAvailable: Boolean,
    knowledgeSamples: { type: Object, default: () => ({}) },
    providerSettings: { type: Object, default: null },
    channels: { type: Array, default: () => [] },
    collections: { type: Array, default: () => [] },
});

const page = usePage();
const form = useHttp({ language: 'en', local_only: true, sandbox: false });
const providers = useForm({
    primary: props.providerSettings?.primary ?? 'ollama',
    allow_cloud: props.providerSettings?.allow_cloud ?? false,
    ollama_model: props.providerSettings?.ollama_model ?? '',
    ollama_fallback_model: props.providerSettings?.ollama_fallback_model ?? '',
    ollama_port: props.providerSettings?.ollama_port ?? 11434,
    embedding_model: props.providerSettings?.embedding_model ?? '',
    openai_model: props.providerSettings?.openai_model ?? '',
    openai_key: '',
    remove_openai_key: false,
});
const routing = useForm({
    routing: JSON.stringify({ agents: props.providerSettings?.agents ?? {}, rules: props.providerSettings?.rules ?? [] }, null, 2),
});
const channel = useForm({ id: '', enabled: false, agent: '', collections: [] });
const readiness = computed(() => {
    const selected = props.channels.find(({ id }) => id === channel.id);
    if (!selected) return [];

    const saved = props.providerSettings.channels[channel.id];
    return [
        !props.providerSettings.auto_reply && 'ai_global_off',
        !props.outboundEnabled && 'ai_outbound_off',
        !selected.enabled && 'ai_account_disabled',
        !Number(selected.has_send_token) && 'ai_channel_token_missing',
        !saved?.enabled && 'ai_channel_auto_off',
        !props.collections.some(({ id }) => saved?.collections?.includes(id)) && 'ai_live_knowledge_missing',
    ].filter(Boolean);
});

function selectChannel() {
    const settings = props.providerSettings.channels[channel.id];
    channel.enabled = settings?.enabled ?? false;
    channel.agent = settings?.agent ?? '';
    channel.collections = [...(settings?.collections ?? [])];
    channel.clearErrors();
}

function saveProviders() {
    providers.patch('/converse/playground/providers', {
        preserveScroll: true,
        onSuccess: () => providers.reset('openai_key', 'remove_openai_key'),
    });
}
const result = ref(null);
const completed = ref(false);
const failed = ref(false);

async function run() {
    result.value = null;
    completed.value = false;
    failed.value = false;

    try {
        const response = await form.post('/converse/playground');
        result.value = response.draft;
        completed.value = true;
    } catch {
        failed.value = true;
    }
}
</script>

<template>
    <Head :title="page.props.labels.playground" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.playground" />
        <div v-if="providerSettings" class="mb-4 grid gap-3 xl:grid-cols-2">
            <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="providers-title">
                <h2 id="providers-title" class="mb-3 font-semibold">{{ page.props.labels.ai_providers }}</h2>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveProviders">
                    <FormField v-slot="attrs" :label="page.props.labels.ai_primary" :error="providers.errors.primary" required>
                        <select v-model="providers.primary" v-bind="attrs" class="ui-input">
                            <option value="ollama">Ollama</option>
                            <option value="openai">OpenAI</option>
                        </select>
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_ollama_port" :error="providers.errors.ollama_port" required>
                        <input v-model.number="providers.ollama_port" v-bind="attrs" class="ui-input" type="number" min="1" max="65535">
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_ollama_model" :error="providers.errors.ollama_model">
                        <input v-model="providers.ollama_model" v-bind="attrs" class="ui-input" maxlength="255">
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_ollama_fallback" :error="providers.errors.ollama_fallback_model">
                        <input v-model="providers.ollama_fallback_model" v-bind="attrs" class="ui-input" maxlength="255">
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_embedding_model" :error="providers.errors.embedding_model">
                        <input v-model="providers.embedding_model" v-bind="attrs" class="ui-input" maxlength="255">
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_openai_model" :error="providers.errors.openai_model">
                        <input v-model="providers.openai_model" v-bind="attrs" class="ui-input" maxlength="255">
                    </FormField>
                    <FormField v-slot="attrs" :label="page.props.labels.ai_openai_key" :error="providers.errors.openai_key">
                        <input
                            v-model="providers.openai_key"
                            v-bind="attrs"
                            type="password"
                            autocomplete="new-password"
                            maxlength="4096"
                            class="ui-input placeholder:text-muted"
                            :placeholder="providers.remove_openai_key
                                ? page.props.labels.send_token_pending_removal
                                : providerSettings.has_openai_key ? `•••••••• · ${page.props.labels.send_token_saved}` : page.props.labels.send_token_empty"
                            :disabled="providers.remove_openai_key"
                        >
                    </FormField>
                    <label v-if="providerSettings.has_openai_key" class="flex items-center gap-2 text-xs sm:col-span-2">
                        <input v-model="providers.remove_openai_key" type="checkbox" class="accent-brand" @change="providers.openai_key = ''">
                        {{ page.props.labels.ai_remove_openai_key }}
                    </label>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input v-model="providers.allow_cloud" type="checkbox" class="accent-brand">
                        {{ page.props.labels.ai_allow_cloud_samples }}
                    </label>
                    <button type="submit" :disabled="providers.processing" class="ui-button justify-self-start">{{ page.props.labels.save }}</button>
                </form>
            </section>
            <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="ai-channel-title">
                <h2 id="ai-channel-title" class="mb-3 font-semibold">{{ page.props.labels.ai_channel_setup }}</h2>
                <form class="grid gap-3" @submit.prevent="channel.patch(`/converse/playground/channels/${channel.id}`, { preserveScroll: true })">
                    <FormField v-slot="attrs" :label="page.props.labels.channel">
                        <select v-model="channel.id" v-bind="attrs" class="ui-input" @change="selectChannel">
                            <option value="">{{ page.props.labels.ai_select_channel }}</option>
                            <option v-for="item in channels" :key="item.id" :value="item.id">{{ item.name }} · {{ page.props.labels[item.type] }}</option>
                        </select>
                    </FormField>
                    <template v-if="channel.id">
                        <FormField v-slot="attrs" :label="page.props.labels.ai_agent" :error="channel.errors.agent">
                            <select v-model="channel.agent" v-bind="attrs" class="ui-input">
                                <option value="">{{ page.props.labels.ai_agent_router }}</option>
                                <option v-for="(agent, key) in providerSettings.agents" :key="key" :value="key">{{ agent.name || key }}</option>
                            </select>
                        </FormField>
                        <FormField v-slot="attrs" :label="page.props.labels.collections" :error="Object.values(channel.errors)[0]">
                            <select v-model="channel.collections" v-bind="attrs" multiple class="ui-input min-h-16">
                                <option v-for="item in collections" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </FormField>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="channel.enabled" type="checkbox" class="accent-brand">
                            {{ page.props.labels.ai_channel_enabled }}
                        </label>
                        <ul v-if="readiness.length" class="list-disc space-y-1 ps-5 text-xs text-muted">
                            <li v-for="key in readiness" :key="key">{{ page.props.labels[key] }}</li>
                        </ul>
                        <Link v-if="readiness.includes('ai_live_knowledge_missing')" href="/converse/knowledge" class="text-xs text-brand underline">
                            {{ page.props.labels.knowledge }}
                        </Link>
                        <button type="submit" :disabled="channel.processing" class="ui-button justify-self-start">{{ page.props.labels.save }}</button>
                    </template>
                </form>
            </section>
        </div>
        <section v-if="providerSettings" class="mb-4 rounded-lg border border-line bg-surface p-4" aria-labelledby="ai-routing-title">
            <h2 id="ai-routing-title" class="mb-2 font-semibold">{{ page.props.labels.ai_routing_settings }}</h2>
            <p class="mb-3 text-xs text-muted">{{ page.props.labels.ai_routing_help }}</p>
            <form class="grid gap-2" @submit.prevent="routing.patch('/converse/playground/routing', { preserveScroll: true })">
                <FormField v-slot="attrs" :label="page.props.labels.ai_routing_json" :error="Object.values(routing.errors)[0]" required>
                    <textarea v-bind="attrs" v-model="routing.routing" class="ui-input min-h-44 font-mono" spellcheck="false" maxlength="30000" />
                </FormField>
                <button type="submit" :disabled="routing.processing" class="ui-button justify-self-start">{{ page.props.labels.save }}</button>
            </form>
        </section>
        <section class="max-w-2xl rounded-lg border border-line bg-surface p-4">
            <p class="mb-4 text-[13px] text-muted">{{ page.props.labels.playground_help }}</p>
            <form class="space-y-3" @submit.prevent="run">
                <label class="block text-xs font-medium">
                    {{ page.props.labels.language_selector }}
                    <select v-model="form.language" class="ui-input mt-1 max-w-52" :disabled="form.processing">
                        <option value="en">English</option>
                        <option value="bn" lang="bn">বাংলা</option>
                        <option value="banglish">Banglish</option>
                    </select>
                </label>
                <p class="rounded-md bg-page p-3 text-[13px]">{{ (form.sandbox ? knowledgeSamples : samples)[form.language] }}</p>
                <label v-if="sandboxAvailable" class="flex items-center gap-2 text-sm">
                    <input v-model="form.sandbox" type="checkbox" class="accent-brand">
                    {{ page.props.labels.ai_sandbox_knowledge }}
                </label>
                <label v-if="cloudAvailable && !form.sandbox" class="flex items-center gap-2 text-sm">
                    <input v-model="form.local_only" type="checkbox" class="accent-brand" :disabled="form.processing">
                    {{ page.props.labels.playground_local }}
                </label>
                <button type="submit" class="ui-button ui-button-primary" :disabled="form.processing">
                    {{ page.props.labels[form.processing ? 'playground_running' : 'playground_run'] }}
                </button>
            </form>
            <div class="mt-4 text-[13px]" role="status">
                <template v-if="result">
                    <p v-if="result.provider" class="font-medium">{{ result.provider }} · {{ result.model }}<span v-if="result.fallback"> · {{ page.props.labels.playground_fallback }}</span></p>
                    <p v-if="result.source_ids?.length" class="text-xs text-muted">{{ page.props.labels.ai_sources }} {{ result.source_ids.join(', ') }}</p>
                    <p class="mt-1 whitespace-pre-wrap wrap-anywhere">{{ result.text }}</p>
                </template>
                <p v-else-if="completed || failed" class="text-muted">{{ page.props.labels.playground_unavailable }}</p>
            </div>
        </section>
    </AdminLayout>
</template>
