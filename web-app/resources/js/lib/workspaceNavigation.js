import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useWorkspaceNavigation() {
    const page = usePage();

    return computed(() => [
        { group: 'workspace_group', label: 'dashboard', href: '/', icon: 'chart-line', pages: ['Dashboard'] },
        ...(page.props.canViewConverseInbox ? [
            { group: 'converse', label: 'inbox', href: '/converse/inbox', icon: 'inbox', search: 'search_conversations', pages: ['Converse/Inbox'] },
        ] : []),
        ...(page.props.canManageConverseKnowledge ? [
            { group: 'converse', label: 'knowledge', href: '/converse/knowledge', icon: 'book', pages: ['Converse/Knowledge'] },
        ] : []),
        ...(page.props.canManageConverseChannels ? [
            { group: 'converse', label: 'playground', href: '/converse/playground', icon: 'play', pages: ['Converse/Playground'] },
        ] : []),
        ...(page.props.canViewConverseAiCalls ? [
            { group: 'converse', label: 'ai_call_history', href: '/converse/ai-calls', icon: 'chart-line', pages: ['Converse/AiCalls'] },
        ] : []),
        ...(page.props.canManageConverseChannels ? [
            { group: 'admin_group', label: 'channels', href: '/converse/channels', icon: 'plug', pages: ['Converse/Channels'] },
        ] : []),
        ...(page.props.canViewUsers ? [
            { group: 'admin_group', label: 'users', href: '/users', icon: 'users', search: 'search_users', pages: ['Users/Index'] },
        ] : []),
        ...(page.props.canManageSettings ? [
            { group: 'admin_group', label: 'settings', href: '/settings', icon: 'settings', pages: ['Settings/Index'] },
        ] : []),
    ]);
}
