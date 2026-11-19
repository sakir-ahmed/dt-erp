<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import WorkspacePreferences from '../components/WorkspacePreferences.vue';

const page = usePage();
const navigation = ref(null);
</script>

<template>
    <div class="min-h-dvh lg:grid lg:grid-cols-[220px_minmax(0,1fr)]">
        <aside class="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface lg:flex">
            <Link href="/" class="flex h-12 shrink-0 items-center gap-2 border-b border-line px-3 font-display text-sm font-semibold">
                <span class="grid size-7 place-items-center rounded-md bg-brand text-on-brand"><AppIcon name="cubes" /></span>
                DT ERP
            </Link>
            <nav :aria-label="page.props.labels.navigation" class="space-y-3 p-2">
                <div>
                    <p class="mb-1 px-2 text-[11px] font-semibold text-muted">{{ page.props.labels.workspace_group }}</p>
                    <Link href="/" class="flex h-8 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected"
                        :aria-current="page.component === 'Dashboard' ? 'page' : undefined"
                        :class="{ 'bg-selected font-semibold text-brand': page.component === 'Dashboard' }">
                        <AppIcon name="dashboard" />{{ page.props.labels.dashboard }}
                    </Link>
                </div>
                <div v-if="page.props.canViewProducts">
                    <p class="mb-1 px-2 text-[11px] font-semibold text-muted">{{ page.props.labels.inventory }}</p>
                    <Link href="/products" class="flex h-8 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected"
                        :aria-current="['Products/Index', 'Stock/Index'].includes(page.component) ? 'page' : undefined"
                        :class="{ 'bg-selected font-semibold text-brand': ['Products/Index', 'Stock/Index'].includes(page.component) }">
                        <AppIcon name="cubes" />{{ page.props.labels.products }}
                    </Link>
                </div>
                <div v-if="page.props.canViewUsers">
                    <p class="mb-1 px-2 text-[11px] font-semibold text-muted">{{ page.props.labels.admin_group }}</p>
                    <Link href="/users" class="flex h-8 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected"
                        :aria-current="page.component === 'Users/Index' ? 'page' : undefined"
                        :class="{ 'bg-selected font-semibold text-brand': page.component === 'Users/Index' }">
                        <AppIcon name="users" />{{ page.props.labels.users }}
                    </Link>
                </div>
            </nav>
        </aside>

        <dialog ref="navigation" :aria-label="page.props.labels.navigation" class="m-0 h-dvh max-h-none w-[220px] max-w-[85vw] border-r border-line bg-surface text-ink backdrop:bg-black/45">
            <div class="flex h-12 items-center justify-between border-b border-line px-3 text-sm font-semibold">
                DT ERP
                <button type="button" class="ui-button ui-button-icon" :aria-label="page.props.labels.close" @click="navigation.close()">
                    <AppIcon name="close" />
                </button>
            </div>
            <nav :aria-label="page.props.labels.navigation" class="space-y-1 p-2">
                <Link href="/" class="flex h-9 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected" @click="navigation.close()">
                    <AppIcon name="dashboard" />{{ page.props.labels.dashboard }}
                </Link>
                <Link v-if="page.props.canViewProducts" href="/products" class="flex h-9 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected" @click="navigation.close()">
                    <AppIcon name="cubes" />{{ page.props.labels.products }}
                </Link>
                <Link v-if="page.props.canViewUsers" href="/users" class="flex h-9 items-center gap-2 rounded-md px-2 text-[13px] hover:bg-selected" @click="navigation.close()">
                    <AppIcon name="users" />{{ page.props.labels.users }}
                </Link>
            </nav>
        </dialog>

        <div class="min-w-0">
            <header class="sticky top-0 z-10 flex min-h-12 items-center justify-between gap-2 border-b border-line bg-surface px-3">
                <div class="flex items-center gap-2">
                    <button type="button" class="ui-button ui-button-icon lg:hidden" :aria-label="page.props.labels.open_navigation" @click="navigation.showModal()">
                        <AppIcon name="menu" />
                    </button>
                    <span class="text-[13px] font-semibold">DT ERP</span>
                </div>
                <div class="flex items-center gap-2">
                    <WorkspacePreferences />
                    <Link href="/logout" method="post" as="button" class="ui-button">{{ page.props.labels.sign_out }}</Link>
                </div>
            </header>
            <main class="min-w-0 p-3 md:p-4"><slot /></main>
        </div>
    </div>
</template>
