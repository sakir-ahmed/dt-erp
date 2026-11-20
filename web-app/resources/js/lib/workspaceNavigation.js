import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useWorkspaceNavigation() {
    const page = usePage();

    return computed(() => [
        { group: 'workspace_group', label: 'dashboard', href: '/', icon: 'chart-line', pages: ['Dashboard'] },
        ...(page.props.canViewConverseInbox ? [
            { group: 'converse', label: 'inbox', href: '/converse/inbox', icon: 'inbox', search: 'search_conversations', pages: ['Converse/Inbox'] },
        ] : []),
        ...(page.props.canManageConverseChannels ? [
            { group: 'converse', label: 'channels', href: '/converse/channels', icon: 'plug', pages: ['Converse/Channels'] },
            { group: 'converse', label: 'playground', href: '/converse/playground', icon: 'play', pages: ['Converse/Playground'] },
            { group: 'converse', label: 'converse_development', href: '/converse/development', icon: 'book', pages: ['Converse/Development'] },
        ] : []),
        ...(page.props.canManageConverseKnowledge ? [
            { group: 'converse', label: 'knowledge', href: '/converse/knowledge', icon: 'book', pages: ['Converse/Knowledge'] },
        ] : []),
        ...(page.props.canViewUsers ? [
            { group: 'admin_group', label: 'users', href: '/users', icon: 'users', search: 'search_users', pages: ['Users/Index'] },
        ] : []),
    ]);
}
