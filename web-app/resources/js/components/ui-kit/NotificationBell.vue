<script setup>
import { Link, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import { formatNumber } from '../../lib/format';
import { useConverseNotifications } from '../../lib/useConverseNotifications';
import { workspaceNotifications } from '../../lib/workspaceNotifications';
import AppIcon from '../AppIcon.vue';

const page = usePage();
const panelId = useId();
const { enabled, supported, toggle } = useConverseNotifications(page);
const acknowledgement = useHttp({});
const acknowledgeFailed = ref(false);
const totalUnread = computed(() => workspaceNotifications.unread + workspaceNotifications.converseHandoffs.length);

async function acknowledge(id) {
    acknowledgeFailed.value = false;
    try {
        await acknowledgement.post(`/converse/inbox/notifications/${id}/acknowledge`);
        workspaceNotifications.converseHandoffs = workspaceNotifications.converseHandoffs.filter((event) => event.id !== id);
        if (workspaceNotifications.converseAlert?.id === id) workspaceNotifications.converseAlert = null;
    } catch {
        acknowledgeFailed.value = true;
    }
}

function openConversation(id) {
    if (workspaceNotifications.converseAlert?.id === id) workspaceNotifications.converseAlert = null;
}

function notificationLink(event) {
    return event.task_id && page.props.canReviewConverseAi
        ? '/converse/review-queue?task=' + event.task_id
        : '/converse/inbox/' + event.conversation_id;
}

function markRead(event) {
    if (event.newState === 'open') {
        workspaceNotifications.unread = 0;
    }
}
</script>

<template>
    <div>
        <button type="button" class="ui-header-icon relative" :popovertarget="panelId" :aria-label="page.props.labels.notifications">
            <AppIcon name="bell" />
            <span v-if="totalUnread" class="absolute end-0.5 top-0.5 min-w-3.5 rounded-full bg-danger px-0.5 text-center text-[9px] font-semibold text-surface">
                {{ formatNumber(totalUnread, page.props.locale) }}
            </span>
        </button>
        <section :id="panelId" popover class="fixed inset-auto end-3 top-12 m-0 w-80 max-w-[calc(100vw-1.5rem)] rounded-lg border border-line bg-surface text-ink shadow-lg" @toggle="markRead">
            <div class="flex items-center justify-between gap-2 border-b border-line px-3 py-2">
                <h2 class="text-sm font-semibold">{{ page.props.labels.notifications }}</h2>
                <button v-if="page.props.canViewConverseInbox && supported" type="button" class="ui-button text-xs"
                    :aria-pressed="enabled" @click="toggle">
                    {{ page.props.labels[enabled ? 'converse_sounds_on' : 'converse_sounds_off'] }}
                </button>
            </div>
            <ul v-if="workspaceNotifications.converseHandoffs.length" class="max-h-60 divide-y divide-line overflow-y-auto">
                <li v-for="event in workspaceNotifications.converseHandoffs" :key="event.id" class="grid gap-2 border-b border-line p-3">
                    <p class="text-sm font-semibold">{{ page.props.labels[event.type === 'human_handoff' ? 'converse_auto_handoff' : 'converse_manual_handoff'] }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Link class="ui-button" :href="notificationLink(event)" @click="openConversation(event.id)">{{ page.props.labels.converse_open_conversation }}</Link>
                        <button type="button" class="ui-button" :disabled="acknowledgement.processing" @click="acknowledge(event.id)">{{ page.props.labels.converse_acknowledge }}</button>
                    </div>
                </li>
            </ul>
            <p v-if="acknowledgeFailed" class="px-3 py-2 text-xs text-danger" role="alert">{{ page.props.labels.converse_acknowledge_failed }}</p>
            <ul v-if="workspaceNotifications.items.length" class="max-h-72 divide-y divide-line overflow-y-auto">
                <li v-for="(message, index) in workspaceNotifications.items" :key="index" class="px-3 py-2 text-[13px]">{{ message }}</li>
            </ul>
            <p v-else-if="!workspaceNotifications.converseHandoffs.length" class="px-3 py-6 text-center text-[13px] text-muted">{{ page.props.labels.no_notifications }}</p>
        </section>
        <aside v-if="workspaceNotifications.converseAlert" class="fixed end-3 top-14 z-30 w-80 max-w-[calc(100vw-1.5rem)] rounded-lg border border-line bg-surface p-3 shadow-lg"
            :aria-label="page.props.labels.converse_auto_handoff">
            <p class="flex items-center gap-2 text-sm font-semibold" role="status">
                <AppIcon name="bell" />
                {{ page.props.labels.converse_auto_handoff }}
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                <Link class="ui-button ui-button-primary" :href="notificationLink(workspaceNotifications.converseAlert)"
                    @click="openConversation(workspaceNotifications.converseAlert.id)">{{ page.props.labels.converse_open_conversation }}</Link>
                <button type="button" class="ui-button" :disabled="acknowledgement.processing"
                    @click="acknowledge(workspaceNotifications.converseAlert.id)">{{ page.props.labels.converse_acknowledge }}</button>
            </div>
            <p v-if="acknowledgeFailed" class="mt-2 text-xs text-danger" role="alert">{{ page.props.labels.converse_acknowledge_failed }}</p>
        </aside>
    </div>
</template>
