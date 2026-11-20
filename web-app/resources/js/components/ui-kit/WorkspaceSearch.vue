<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { useWorkspaceNavigation } from '../../lib/workspaceNavigation';
import AppIcon from '../AppIcon.vue';

const page = usePage();
const entries = useWorkspaceNavigation();
const query = ref('');
const dialog = ref(null);
const titleId = useId();
const searchText = computed(() => query.value.trim());
const results = computed(() => {
    const term = searchText.value.toLocaleLowerCase();
    const pages = entries.value
        .filter((entry) => page.props.labels[entry.label].toLocaleLowerCase().includes(term));

    return term ? [
        ...pages,
        ...entries.value.filter((entry) => entry.search).map((entry) => ({
            icon: 'search',
            label: entry.search,
            href: `${entry.href}?search=${encodeURIComponent(searchText.value)}`,
        })),
    ] : pages;
});

function open() {
    if (dialog.value.open || document.querySelector('dialog[open]')) {
        return;
    }

    query.value = '';
    dialog.value.showModal();
}

function shortcut(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !event.defaultPrevented) {
        event.preventDefault();
        open();
    }
}

function visitFirst() {
    if (results.value.length) {
        dialog.value.close();
        router.visit(results.value[0].href);
    }
}

onMounted(() => window.addEventListener('keydown', shortcut));
onBeforeUnmount(() => window.removeEventListener('keydown', shortcut));
</script>

<template>
    <div>
        <button
            type="button"
            class="ui-header-icon md:flex md:h-7 md:w-auto md:gap-1.5 md:border md:border-line md:bg-page md:px-2 md:text-xs"
            :aria-label="page.props.labels.workspace_search"
            aria-haspopup="dialog"
            aria-keyshortcuts="Control+K Meta+K"
            @click="open"
        >
            <AppIcon name="search" />
            <span class="hidden md:inline">{{ page.props.labels.search }}</span>
            <kbd class="hidden rounded border border-line px-1 text-[10px] lg:inline" aria-hidden="true">Ctrl K</kbd>
        </button>

        <Teleport to="body">
            <dialog
                ref="dialog"
                :aria-labelledby="titleId"
                class="mx-auto mt-[12dvh] w-lg max-w-[calc(100vw-1.5rem)] rounded-xl border border-line bg-surface text-ink shadow-xl backdrop:bg-black/40"
                @click.self="dialog.close()"
            >
                <div>
                    <h2 :id="titleId" class="sr-only">{{ page.props.labels.workspace_search }}</h2>
                    <form class="flex items-center gap-2 border-b border-line p-3" role="search" @submit.prevent="visitFirst">
                        <AppIcon name="search" class="text-muted" />
                        <input v-model="query" type="search" maxlength="100" class="ui-input" :aria-label="page.props.labels.workspace_search" :placeholder="page.props.labels.workspace_search" autofocus>
                        <button type="button" class="ui-header-icon" :aria-label="page.props.labels.close" @click="dialog.close()"><AppIcon name="close" /></button>
                    </form>
                    <ul class="max-h-[50dvh] overflow-y-auto p-2">
                        <li v-for="result in results" :key="result.href">
                            <Link :href="result.href" class="flex items-center gap-2 rounded-md px-2 py-2 text-[13px] hover:bg-page focus-visible:bg-page" @click="dialog.close()">
                                <AppIcon :name="result.icon" class="text-muted" />
                                {{ page.props.labels[result.label] }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </dialog>
        </Teleport>
    </div>
</template>
