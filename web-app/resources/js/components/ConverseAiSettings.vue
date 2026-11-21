<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConverseWorkflowEditor from './ConverseWorkflowEditor.vue';
import FormField from './ui-kit/FormField.vue';

const props = defineProps({
    providerSettings: { type: Object, required: true },
    channels: { type: Array, required: true },
    collections: { type: Array, required: true },
    outboundEnabled: Boolean,
    autoReply: Boolean,
});

const page = usePage();
const providers = useForm({
    primary: props.providerSettings?.primary ?? 'ollama',
    allow_cloud: props.providerSettings?.allow_cloud ?? false,
    ollama_enabled: props.providerSettings?.ollama_enabled ?? true,
    ollama_model: props.providerSettings?.ollama_model ?? '',
    ollama_fallback_model: props.providerSettings?.ollama_fallback_model ?? '',
    ollama_port: props.providerSettings?.ollama_port ?? 11434,
    embedding_model: props.providerSettings?.embedding_model ?? '',
    openai_model: props.providerSettings?.openai_model ?? '',
});
const routing = useForm({
    routing: JSON.stringify({
        agents: props.providerSettings?.agents ?? {},
        rules: props.providerSettings?.rules ?? [],
        cloud: props.providerSettings?.cloud ?? { live: false, models: [] },
    }, null, 2),
});
const channel = useForm({ id: '', enabled: false, agent: '', collections: [] });
const readiness = computed(() => {
    const selected = props.channels.find(({ id }) => id === channel.id);
    if (!selected) return [];

    const saved = props.providerSettings.channels[channel.id];
    return [
        (!props.autoReply || !props.providerSettings.auto_reply) && 'ai_global_off',
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
    providers.patch('/settings/converse/providers', {
        preserveScroll: true,
    });
}
</script>

<template>
    <section id="converse-ai" class="grid scroll-mt-20 gap-3 lg:col-span-2" aria-labelledby="converse-ai-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="converse-ai-title" class="font-semibold">{{ page.props.labels.settings_converse_ai }}</h2>
            <Link href="/converse/playground" class="text-xs text-brand underline">{{ page.props.labels.playground }}</Link>
        </div>
        <ConverseWorkflowEditor :settings="providerSettings" />
        <div class="grid gap-3 xl:grid-cols-2">
            <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="providers-title">
                <h2 id="providers-title" class="mb-3 font-semibold">{{ page.props.labels.ai_providers }}</h2>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveProviders">
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input v-model="providers.ollama_enabled" type="checkbox" class="accent-brand">
                        {{ page.props.labels.ai_ollama_enabled }}
                    </label>
                    <p class="-mt-2 text-xs text-muted sm:col-span-2">{{ page.props.labels.ai_ollama_enabled_help }}</p>
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
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input v-model="providers.allow_cloud" type="checkbox" class="accent-brand">
                        {{ page.props.labels.ai_allow_cloud_samples }}
                    </label>
                    <p class="text-xs text-muted sm:col-span-2">
                        {{ page.props.labels.ai_cloud_connected }} {{ providerSettings.cloud_providers.join(', ') || page.props.labels.ai_cloud_none }}
                    </p>
                    <button type="submit" :disabled="providers.processing" class="ui-button justify-self-start">{{ page.props.labels.save }}</button>
                </form>
            </section>
            <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="ai-channel-title">
                <h2 id="ai-channel-title" class="mb-3 font-semibold">{{ page.props.labels.ai_channel_setup }}</h2>
                <form class="grid gap-3" @submit.prevent="channel.patch(`/settings/converse/channels/${channel.id}`, { preserveScroll: true })">
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
        <section class="rounded-lg border border-line bg-surface p-4" aria-labelledby="ai-routing-title">
            <h2 id="ai-routing-title" class="mb-2 font-semibold">{{ page.props.labels.ai_routing_settings }}</h2>
            <p class="mb-3 text-xs text-muted">{{ page.props.labels.ai_routing_help }}</p>
            <p class="mb-3 text-xs text-muted">{{ page.props.labels.ai_cloud_routing_help }}</p>
            <form class="grid gap-2" @submit.prevent="routing.patch('/settings/converse/routing', { preserveScroll: true })">
                <FormField v-slot="attrs" :label="page.props.labels.ai_routing_json" :error="Object.values(routing.errors)[0]" required>
                    <textarea v-bind="attrs" v-model="routing.routing" class="ui-input min-h-44 font-mono" spellcheck="false" maxlength="30000" />
                </FormField>
                <button type="submit" :disabled="routing.processing" class="ui-button justify-self-start">{{ page.props.labels.save }}</button>
            </form>
        </section>
    </section>
</template>
