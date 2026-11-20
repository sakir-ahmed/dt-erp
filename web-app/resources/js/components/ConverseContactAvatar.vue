<script setup>
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';

const props = defineProps({
    name: { type: String, default: null },
    photo: { type: String, default: null },
});
const failed = ref(false);
watch(() => props.photo, () => { failed.value = false; });
const initials = computed(() => (props.name ?? '').trim().split(/\s+/).filter(Boolean).slice(0, 2)
    .map((part) => Array.from(part)[0]).join('').toLocaleUpperCase());
</script>

<template>
    <span class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-full bg-page text-xs font-semibold text-muted" aria-hidden="true">
        <img v-if="photo && !failed" :src="photo" alt="" referrerpolicy="no-referrer" class="size-full object-cover" @error="failed = true">
        <span v-else-if="initials">{{ initials }}</span>
        <AppIcon v-else name="users" />
    </span>
</template>
