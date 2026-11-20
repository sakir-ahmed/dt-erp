<script setup>
import { onBeforeUnmount } from 'vue';
import AppIcon from '../AppIcon.vue';

const query = defineModel({ type: String, required: true });

defineProps({
    label: { type: String, required: true },
    clearLabel: { type: String, required: true },
    hasFilter: Boolean,
    busy: Boolean,
});

const emit = defineEmits(['search']);
let searchTimer;

function search() {
    window.clearTimeout(searchTimer);
    emit('search');
}

function queueSearch(event) {
    window.clearTimeout(searchTimer);

    if (event.isComposing) {
        return;
    }

    searchTimer = window.setTimeout(search, 300);
}

onBeforeUnmount(() => window.clearTimeout(searchTimer));

function clear() {
    query.value = '';
    search();
}
</script>

<template>
    <form role="search" :aria-busy="busy" class="flex flex-wrap items-center gap-2 border-b border-line p-3" @submit.prevent="search">
        <div class="relative w-60 max-w-full">
            <input v-model="query" type="search" maxlength="100" class="ui-input pe-9" :aria-label="label" :placeholder="label" @input="queueSearch">
            <button type="submit" class="absolute inset-y-0 end-0 grid w-9 place-items-center rounded-e-md text-muted hover:text-ink disabled:opacity-50" :aria-label="label" :disabled="busy">
                <AppIcon name="search" />
            </button>
        </div>
        <button v-if="hasFilter" type="button" class="ui-button" :disabled="busy" @click="clear">{{ clearLabel }}</button>
    </form>
</template>
