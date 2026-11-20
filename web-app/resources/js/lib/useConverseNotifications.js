import { onBeforeUnmount, onMounted, ref } from 'vue';
import { converseNotificationCues, readConverseEvents, scheduleConverseSound, selectConverseCues } from './converseNotificationSounds';
import { recordWorkspaceNotification, workspaceNotifications } from './workspaceNotifications';

const preferenceKey = 'dt-erp-converse-notification-sounds';
let context = null;
let nextSound = 0;

export function useConverseNotifications(page) {
    const enabled = ref(false);
    const supported = ref(false);
    let timer;
    let active = false;

    async function unlock() {
        if (!enabled.value || !supported.value) return;

        try {
            context ??= new (window.AudioContext || window.webkitAudioContext)();
            if (context.state !== 'running') await context.resume();
        } catch {
            // Visual notifications still work when sound is unavailable.
        }
    }

    function toggle() {
        enabled.value = !enabled.value;
        try {
            localStorage.setItem(preferenceKey, enabled.value ? '1' : '0');
        } catch {
            // The preference remains available for the current page.
        }
        if (enabled.value) void unlock();
        else if (context) {
            void context.close().catch(() => {});
            context = null;
            nextSound = 0;
        }
    }

    async function play(events, account) {
        if (!enabled.value || context?.state !== 'running' || !events.length) return;
        const pick = () => {
            if (!active || !enabled.value || context?.state !== 'running' || workspaceNotifications.converseAccount !== account) return;
            const key = `${preferenceKey}:last:${account}`;
            let lastPlayed = 0;
            try {
                lastPlayed = Number(localStorage.getItem(key)) || 0;
                localStorage.setItem(key, String(Math.max(lastPlayed, events.at(-1).id)));
            } catch {
                // The in-memory poll cursor still prevents replay in this tab.
            }

            const cues = selectConverseCues(events, lastPlayed, enabled.value && context.state === 'running');
            for (const type of cues) {
                nextSound = scheduleConverseSound(context, type, Math.max(context.currentTime + 0.01, nextSound));
            }
        };
        if (navigator.locks?.request) await navigator.locks.request(preferenceKey, pick);
        else pick();
    }

    async function poll() {
        try {
            const account = page.props.account?.email ?? null;
            if (!page.props.canViewConverseInbox || !account) return;
            if (account !== workspaceNotifications.converseAccount) {
                workspaceNotifications.converseCursor = null;
                workspaceNotifications.converseHandoffs = [];
                workspaceNotifications.converseAlert = null;
                workspaceNotifications.converseAccount = account;
            }
            const cursor = workspaceNotifications.converseCursor;
            const url = cursor === null ? '/converse/inbox/notifications' : `/converse/inbox/notifications?after=${cursor}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok || !active || page.props.account?.email !== account) return;
            const payload = await response.json();
            const batch = readConverseEvents(cursor, payload);
            if (!active || page.props.account?.email !== account) return;
            workspaceNotifications.converseCursor = batch.cursor;
            if (Array.isArray(payload.handoffs)) {
                workspaceNotifications.converseHandoffs = payload.handoffs;
                const newAutomatic = batch.events.filter(({ type }) => type === 'human_handoff').at(-1);
                const alertId = newAutomatic?.id ?? workspaceNotifications.converseAlert?.id;
                workspaceNotifications.converseAlert = payload.handoffs.find(({ id }) => id === alertId) ?? null;
            }
            for (const event of batch.events) {
                if (event.type === 'ai_inbound' || event.type === 'ai_reply') {
                    recordWorkspaceNotification(page.props.labels[converseNotificationCues[event.type].label]);
                }
            }
            await play(batch.events, account);
        } catch {
            // A failed poll is retried without advancing the cursor.
        } finally {
            if (active) timer = window.setTimeout(poll, 5000);
        }
    }

    onMounted(() => {
        active = true;
        supported.value = Boolean(window.AudioContext || window.webkitAudioContext);
        try {
            enabled.value = localStorage.getItem(preferenceKey) === '1';
        } catch {
            // Sound remains off until explicitly enabled.
        }
        window.addEventListener('pointerdown', unlock);
        window.addEventListener('keydown', unlock);
        void poll();
    });

    onBeforeUnmount(() => {
        active = false;
        window.clearTimeout(timer);
        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
    });

    return { enabled, supported, toggle };
}
