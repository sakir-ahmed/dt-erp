<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import WorkspaceNavigation from '../components/WorkspaceNavigation.vue';
import WorkspacePreferences from '../components/WorkspacePreferences.vue';
import AccountMenu from '../components/ui-kit/AccountMenu.vue';
import ClockPill from '../components/ui-kit/ClockPill.vue';
import ConnectionStatus from '../components/ui-kit/ConnectionStatus.vue';
import NotificationBell from '../components/ui-kit/NotificationBell.vue';
import WorkspaceSearch from '../components/ui-kit/WorkspaceSearch.vue';

const page = usePage();
const navigation = ref(null);
const navigationId = useId();
const collapsed = ref(false);
const desktop = window.matchMedia('(min-width: 1024px)');

function closeNavigation() {
    navigation.value?.close();
}

function closeOnDesktop(event) {
    if (event.matches) {
        closeNavigation();
    }
}

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem('sidebar-collapsed', collapsed.value ? '1' : '0');
    } catch {
        // The current page can still use the selected sidebar width.
    }
}

onMounted(() => {
    try {
        collapsed.value = localStorage.getItem('sidebar-collapsed') === '1';
    } catch {
        // Use the default sidebar width when storage is unavailable.
    }

    desktop.addEventListener('change', closeOnDesktop);
});

onBeforeUnmount(() => desktop.removeEventListener('change', closeOnDesktop));
</script>

<template>
    <div class="min-h-dvh lg:grid" :class="collapsed ? 'lg:grid-cols-[56px_minmax(0,1fr)]' : 'lg:grid-cols-[220px_minmax(0,1fr)]'">
        <aside class="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface lg:flex">
            <Link href="/" class="flex h-12 shrink-0 items-center gap-2 border-b border-line px-3 font-display text-sm font-semibold">
                <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand text-on-brand"><AppIcon name="cubes" /></span>
                <span :class="{ 'sr-only': collapsed }">DT ERP</span>
            </Link>
            <WorkspaceNavigation :collapsed="collapsed" />
            <button type="button" class="flex h-9 shrink-0 cursor-pointer items-center justify-center border-t border-line text-sm text-muted hover:bg-page hover:text-ink" :aria-label="page.props.labels.toggle_sidebar" :aria-pressed="collapsed" @click="toggleSidebar">
                <AppIcon :name="collapsed ? 'chevron-right' : 'chevron-left'" />
            </button>
        </aside>

        <dialog :id="navigationId" ref="navigation" :aria-label="page.props.labels.navigation" class="m-0 h-dvh max-h-none w-[220px] max-w-[85vw] border-r border-line bg-surface text-ink backdrop:bg-black/45" @click.self="closeNavigation">
            <div class="flex h-full flex-col">
                <div class="flex h-12 shrink-0 items-center justify-between border-b border-line px-3 text-sm font-semibold">
                    <Link href="/" class="flex items-center gap-2 font-display" @click="closeNavigation">
                        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-brand text-on-brand"><AppIcon name="cubes" /></span>
                        DT ERP
                    </Link>
                    <button type="button" class="ui-button ui-button-icon" :aria-label="page.props.labels.close" @click="closeNavigation">
                        <AppIcon name="close" />
                    </button>
                </div>
                <WorkspaceNavigation @navigate="closeNavigation" />
            </div>
        </dialog>

        <div class="min-w-0">
            <header class="sticky top-0 z-10 flex min-h-12 items-center justify-between gap-2 border-b border-line bg-surface px-3">
                <div class="flex min-w-0 items-center gap-1 lg:hidden">
                    <button type="button" class="ui-header-icon" :aria-label="page.props.labels.open_navigation" aria-haspopup="dialog" :aria-controls="navigationId" @click="navigation.showModal()">
                        <AppIcon name="menu" />
                    </button>
                    <span class="truncate text-[13px] font-semibold">DT ERP</span>
                </div>
                <div class="ms-auto flex shrink-0 items-center gap-1">
                    <WorkspaceSearch />
                    <ClockPill :locale="page.props.locale" />
                    <ConnectionStatus />
                    <NotificationBell />
                    <WorkspacePreferences />
                    <AccountMenu />
                </div>
            </header>
            <main class="min-w-0 p-3 md:p-4"><slot /></main>
        </div>
    </div>
</template>
