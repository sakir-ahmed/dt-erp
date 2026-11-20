<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useWorkspaceNavigation } from '../lib/workspaceNavigation';
import AppIcon from './AppIcon.vue';

defineProps({ collapsed: Boolean });
const emit = defineEmits(['navigate']);
const page = usePage();

const entries = useWorkspaceNavigation();
const groups = computed(() => Object.groupBy(entries.value, (entry) => entry.group));
</script>

<template>
    <nav :aria-label="page.props.labels.navigation" class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-2 [scrollbar-width:thin]">
        <div v-for="(items, group) in groups" :key="group">
            <p v-if="!collapsed" class="mb-1 px-2 text-[10px] font-semibold text-muted" :class="page.props.locale === 'en' ? 'uppercase tracking-wider' : ''">{{ page.props.labels[group] }}</p>
            <Link
                v-for="entry in items"
                :key="entry.href"
                :href="entry.href"
                :title="collapsed ? page.props.labels[entry.label] : undefined"
                :aria-current="entry.pages.includes(page.component) ? (entry.pages[0] === page.component ? 'page' : 'location') : undefined"
                class="flex h-9 items-center gap-2 rounded-md px-2 text-[13px] transition-colors lg:h-8"
                :class="[
                    collapsed && 'justify-center',
                    entry.pages.includes(page.component)
                        ? 'bg-nav-active font-semibold text-on-brand shadow-sm'
                        : 'text-muted hover:bg-page hover:text-ink',
                ]"
                @click="emit('navigate')"
            >
                <AppIcon :name="entry.icon" class="shrink-0 text-sm" />
                <span class="truncate" :class="{ 'sr-only': collapsed }">{{ page.props.labels[entry.label] }}</span>
            </Link>
        </div>
    </nav>
</template>
