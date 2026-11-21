<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ChannelBrandIcon from '../../components/ChannelBrandIcon.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

defineProps({ channels: { type: Object, required: true }, canConfigureToken: Boolean });

const page = usePage();
const open = ref(false);
const editing = ref(null);
const hasSendToken = ref(false);
const inputs = {};
const formId = useId();
const form = useForm({ name: '', type: 'messenger', external_id: '', enabled: true, send_token: '', remove_send_token: false });
const tokenStatus = computed(() => {
    if (form.remove_send_token) {
        return page.props.labels.send_token_pending_removal;
    }
    return page.props.labels[hasSendToken.value ? 'send_token_saved' : 'send_token_empty'];
});

function edit(channel = null) {
    editing.value = channel?.id ?? null;
    hasSendToken.value = Boolean(channel?.has_send_token);
    form.reset();
    form.clearErrors();

    if (channel) {
        form.name = channel.name;
        form.type = channel.type;
        form.external_id = channel.external_id;
        form.enabled = channel.enabled;
    }

    open.value = true;
}

function save() {
    form.submit(editing.value ? 'patch' : 'post', editing.value ? `/converse/channels/${editing.value}` : '/converse/channels', {
        preserveScroll: true,
        onSuccess: () => { form.reset('send_token'); open.value = false; },
        onError: async (errors) => {
            await nextTick();
            inputs[Object.keys(errors)[0]]?.focus();
        },
    });
}
</script>

<template>
    <Head :title="page.props.labels.channels" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.channels">
            <template #actions>
                <button type="button" class="ui-button ui-button-primary" @click="edit()">
                    <AppIcon name="add" />{{ page.props.labels.add_channel }}
                </button>
            </template>
        </PageHeader>

        <div class="rounded-lg border border-line bg-surface">
            <ul v-if="channels.data.length" class="divide-y divide-line">
                <li v-for="channel in channels.data" :key="channel.id">
                    <button type="button" class="flex w-full cursor-pointer items-center justify-between gap-3 px-3 py-2 text-start hover:bg-page" @click="edit(channel)">
                        <span class="flex min-w-0 flex-1 items-center gap-2">
                            <ChannelBrandIcon :channel="channel.type" class="h-5 w-5 shrink-0" :title="page.props.labels[channel.type]" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold">{{ channel.name }}</span>
                                <span class="block truncate text-xs text-muted">{{ page.props.labels[channel.type] }} · {{ channel.external_id }}</span>
                            </span>
                        </span>
                        <span class="rounded-full px-2 py-0.5 text-xs" :class="channel.enabled ? 'bg-success/10 text-success' : 'bg-page text-muted'">
                            {{ page.props.labels[channel.enabled ? 'enabled' : 'disabled'] }}
                        </span>
                        <AppIcon name="edit" class="text-muted" />
                    </button>
                </li>
            </ul>
            <EmptyState v-else icon="plug" :title="page.props.labels.no_channels" />
            <div v-if="channels.prev_page_url || channels.next_page_url" class="flex justify-end gap-2 border-t border-line p-3">
                <Link v-if="channels.prev_page_url" :href="channels.prev_page_url" class="ui-button">{{ page.props.labels.previous }}</Link>
                <Link v-if="channels.next_page_url" :href="channels.next_page_url" class="ui-button">{{ page.props.labels.next }}</Link>
            </div>
        </div>

        <SlideOver v-if="open" :busy="form.processing" :title="page.props.labels[editing ? 'edit_channel' : 'add_channel']" :close-label="page.props.labels.close" @close="open = false">
            <form :id="formId" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField v-slot="attrs" :label="page.props.labels.name" :error="form.errors.name" required>
                    <input :ref="(el) => inputs.name = el" v-bind="attrs" v-model="form.name" class="ui-input" maxlength="255" autofocus>
                </FormField>
                <FormField v-if="!editing" v-slot="attrs" :label="page.props.labels.channel" :error="form.errors.type" required>
                    <select :ref="(el) => inputs.type = el" v-bind="attrs" v-model="form.type" class="ui-input">
                        <option value="messenger">{{ page.props.labels.messenger }}</option>
                        <option value="whatsapp">{{ page.props.labels.whatsapp }}</option>
                    </select>
                </FormField>
                <FormField v-if="!editing" v-slot="attrs" :label="page.props.labels[form.type === 'messenger' ? 'page_id' : 'phone_number_id']" :error="form.errors.external_id" required>
                    <input :ref="(el) => inputs.external_id = el" v-bind="attrs" v-model="form.external_id" class="ui-input" inputmode="numeric" pattern="[0-9]{1,64}" maxlength="64">
                </FormField>
                <label v-else class="flex items-center gap-2 text-sm">
                    <input v-model="form.enabled" type="checkbox" class="accent-brand">
                    {{ page.props.labels.enabled }}
                </label>
                <p v-if="form.type === 'whatsapp'" class="text-xs text-muted sm:col-span-2">
                    {{ page.props.labels[editing ? 'whatsapp_channel_token_help' : 'whatsapp_channel_id_help'] }}
                </p>
                <div v-if="editing && canConfigureToken" class="grid gap-2 sm:col-span-2">
                    <FormField v-slot="attrs" :label="page.props.labels.send_token" :error="form.errors.send_token">
                        <input
                            :ref="(el) => inputs.send_token = el"
                            v-bind="attrs"
                            v-model="form.send_token"
                            type="password"
                            autocomplete="new-password"
                            maxlength="4096"
                            :placeholder="hasSendToken && !form.remove_send_token ? `•••••••• · ${tokenStatus}` : tokenStatus"
                            :aria-describedby="[attrs['aria-describedby'], `${formId}-token-status`].filter(Boolean).join(' ')"
                            :disabled="form.remove_send_token"
                            class="ui-input placeholder:text-muted"
                        >
                        <span :id="`${formId}-token-status`" class="sr-only" role="status">{{ tokenStatus }}</span>
                    </FormField>
                    <label v-if="hasSendToken" class="flex items-center gap-2 text-xs">
                        <input v-model="form.remove_send_token" type="checkbox" class="accent-brand" @change="form.send_token = ''">
                        {{ page.props.labels.remove_send_token }}
                    </label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="ui-button" :disabled="form.processing" @click="open = false">{{ page.props.labels.cancel }}</button>
                <button type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="form.processing">{{ page.props.labels.save }}</button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
