<script setup>
import AppIcon from '../AppIcon.vue';

defineProps({
    icon: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-3 py-8 text-center">
        <span class="grid size-10 place-items-center rounded-lg bg-selected text-brand">
            <AppIcon :name="icon" />
        </span>
        <p class="text-sm font-semibold">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-[13px] text-muted">{{ description }}</p>
    </div>
</template>
