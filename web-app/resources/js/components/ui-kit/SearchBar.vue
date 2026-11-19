<script setup>
import AppIcon from '../AppIcon.vue';

const query = defineModel({ type: String, required: true });

defineProps({
    label: { type: String, required: true },
    clearLabel: { type: String, required: true },
    hasFilter: Boolean,
    busy: Boolean,
});

const emit = defineEmits(['search']);

function clear() {
    query.value = '';
    emit('search');
}
</script>

<template>
    <form role="search" :aria-busy="busy" class="flex flex-wrap items-center gap-2 border-b border-line p-3" @submit.prevent="emit('search')">
        <input v-model="query" type="search" maxlength="100" class="ui-input max-w-60" :aria-label="label" :placeholder="label">
        <button type="submit" class="ui-button ui-button-icon" :aria-label="label" :disabled="busy">
            <AppIcon name="search" />
        </button>
        <button v-if="hasFilter" type="button" class="ui-button" :disabled="busy" @click="clear">{{ clearLabel }}</button>
    </form>
</template>
