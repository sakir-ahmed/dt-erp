import { reactive } from 'vue';

export const workspaceNotifications = reactive({ items: [], unread: 0, converseAccount: null, converseCursor: null, converseHandoffs: [], converseAlert: null });

export function recordWorkspaceNotification(message) {
    workspaceNotifications.items.unshift(message);
    workspaceNotifications.items.splice(10);
    workspaceNotifications.unread = Math.min(workspaceNotifications.unread + 1, workspaceNotifications.items.length);
}

export function resetWorkspaceNotifications() {
    workspaceNotifications.converseAccount = null;
    workspaceNotifications.converseCursor = null;
    workspaceNotifications.converseHandoffs = [];
    workspaceNotifications.converseAlert = null;
    workspaceNotifications.items = [];
    workspaceNotifications.unread = 0;
}
