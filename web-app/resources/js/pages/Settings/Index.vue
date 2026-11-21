<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import FormField from '../../components/ui-kit/FormField.vue';
import ConverseAiSettings from '../../components/ConverseAiSettings.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SecretField from '../../components/ui-kit/SecretField.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    application: { type: Object, required: true },
    converse: { type: Object, required: true },
    locales: { type: Array, required: true },
    modes: { type: Array, required: true },
    channelTypes: { type: Array, required: true },
    aiProviders: { type: Array, required: true },
    converseAi: { type: Object, default: null },
});

const page = usePage();

const application = useForm({ default_locale: props.application.default_locale });
const converse = useForm({
    auto_reply: Boolean(props.converse.auto_reply),
    new_conversation_mode: props.converse.new_conversation_mode,
    channel_types: Object.fromEntries(props.channelTypes.map((type) => [type, Boolean(props.converse.channel_types?.[type])])),
});
const providerValues = () => Object.fromEntries(props.aiProviders.flatMap(({ id, enabled }) => [
    [id + '_key', ''], ['remove_' + id + '_key', false], ['enable_' + id, enabled],
]));
const providerKeys = useForm(providerValues());

function saveProviderKeys() {
    const current = providerValues();
    providerKeys.transform((values) => Object.fromEntries(
        Object.entries(values).filter(([field, value]) => value !== current[field]),
    )).patch('/settings/ai-providers', {
        preserveScroll: true,
        onSuccess: () => {
            providerKeys.defaults(providerValues());
            providerKeys.reset();
        },
    });
}

watch(() => props.application.default_locale, (value) => application.defaults({ default_locale: value }));
watch(() => props.converse, (value) => converse.defaults({
    auto_reply: Boolean(value.auto_reply),
    new_conversation_mode: value.new_conversation_mode,
    channel_types: Object.fromEntries(props.channelTypes.map((type) => [type, Boolean(value.channel_types?.[type])])),
}), { deep: true });
</script>

<template>
    <Head :title="page.props.labels.settings" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.settings" />
        <div class="grid gap-3 lg:grid-cols-2 lg:items-start">
            <form class="grid gap-3 rounded-lg border border-line bg-surface p-3" @submit.prevent="application.patch('/settings/application')">
                <h2 class="font-semibold">{{ page.props.labels.settings_application }}</h2>
                <FormField v-slot="attrs" :label="page.props.labels.settings_default_locale">
                    <select v-model="application.default_locale" v-bind="attrs" class="ui-input">
                        <option v-for="value in locales" :key="value" :value="value">{{ page.props.labels[`locale_${value}`] }}</option>
                    </select>
                </FormField>
                <p class="text-xs text-muted">{{ page.props.labels.settings_default_locale_help }}</p>
                <button type="submit" class="ui-button ui-button-primary justify-self-start" :disabled="application.processing">
                    {{ page.props.labels.save }}
                </button>
            </form>

            <form class="grid gap-3 rounded-lg border border-line bg-surface p-3" @submit.prevent="converse.patch('/settings/converse')">
                <h2 class="font-semibold">{{ page.props.labels.settings_converse_module }}</h2>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="converse.auto_reply" type="checkbox" class="accent-brand">
                    {{ page.props.labels.settings_converse_auto_reply }}
                </label>
                <p class="text-xs text-muted">{{ page.props.labels.settings_converse_auto_reply_help }}</p>
                <FormField v-slot="attrs" :label="page.props.labels.settings_converse_new_mode">
                    <select v-model="converse.new_conversation_mode" v-bind="attrs" class="ui-input">
                        <option v-for="value in modes" :key="value" :value="value">{{ page.props.labels[`mode_${value}`] }}</option>
                    </select>
                </FormField>
                <p class="text-xs text-muted">{{ page.props.labels.settings_converse_new_mode_help }}</p>
                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">{{ page.props.labels.settings_converse_channel_types }}</legend>
                    <label v-for="type in channelTypes" :key="type" class="flex items-center gap-2 text-sm">
                        <input v-model="converse.channel_types[type]" type="checkbox" class="accent-brand">
                        {{ page.props.labels[type] }}
                    </label>
                    <p class="text-xs text-muted">{{ page.props.labels.settings_converse_channel_types_help }}</p>
                </fieldset>
                <button type="submit" class="ui-button ui-button-primary justify-self-start" :disabled="converse.processing">
                    {{ page.props.labels.save }}
                </button>
            </form>
            <form v-if="aiProviders.length" id="ai-providers"
                class="grid scroll-mt-20 gap-3 rounded-lg border border-line bg-surface p-3 lg:col-span-2"
                @submit.prevent="saveProviderKeys">
                <h2 class="font-semibold">{{ page.props.labels.settings_ai_provider_keys }}</h2>
                <p class="text-xs text-muted">{{ page.props.labels.settings_ai_provider_key_help }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="{ id, name, configured } in aiProviders" :key="id" class="grid content-start gap-2 rounded-md border border-line p-3">
                        <label class="flex items-center gap-2 text-sm font-medium">
                            <input v-model="providerKeys['enable_' + id]" type="checkbox" class="accent-brand" :disabled="providerKeys.processing">
                            {{ page.props.labels.settings_ai_provider_enabled.replace(':provider', name) }}
                        </label>
                        <FormField v-slot="attrs" :label="page.props.labels.settings_ai_provider_key.replace(':provider', name)"
                            :error="providerKeys.errors[id + '_key']">
                            <SecretField v-bind="attrs" v-model="providerKeys[id + '_key']"
                                :placeholder="page.props.labels[providerKeys['remove_' + id + '_key'] ? 'settings_ai_provider_key_pending_removal'
                                    : configured ? 'settings_ai_provider_key_saved' : 'settings_ai_provider_key_empty']"
                                :disabled="providerKeys.processing || providerKeys['remove_' + id + '_key']" />
                        </FormField>
                        <label v-if="configured" class="flex items-center gap-2 text-xs">
                            <input v-model="providerKeys['remove_' + id + '_key']" type="checkbox" class="accent-brand"
                                :disabled="providerKeys.processing" @change="providerKeys[id + '_key'] = ''">
                            {{ page.props.labels.settings_ai_provider_key_remove.replace(':provider', name) }}
                        </label>
                    </div>
                </div>
                <button type="submit" class="ui-button ui-button-primary justify-self-start"
                    :disabled="providerKeys.processing || !providerKeys.isDirty">
                    {{ page.props.labels.settings_ai_provider_keys_save }}
                </button>
            </form>
            <ConverseAiSettings v-if="converseAi" v-bind="converseAi" :auto-reply="Boolean(converse.auto_reply)" />
        </div>
    </AdminLayout>
</template>
