<script setup>
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <h1 class="font-display text-lg font-semibold">{{ title }}</h1>
        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
