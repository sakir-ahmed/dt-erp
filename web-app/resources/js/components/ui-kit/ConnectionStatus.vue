<script setup>
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppIcon from '../AppIcon.vue';

const page = usePage();
const online = ref(navigator.onLine);

function updateConnection() {
    online.value = navigator.onLine;
}

onMounted(() => {
    window.addEventListener('online', updateConnection);
    window.addEventListener('offline', updateConnection);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateConnection);
    window.removeEventListener('offline', updateConnection);
});
</script>

<template>
    <span
        role="status"
        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-full px-1.5 text-xs font-medium sm:px-2"
        :class="online ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
    >
        <AppIcon :name="online ? 'wifi' : 'plug-off'" class="text-[10px]" />
        <span class="sr-only sm:not-sr-only">{{ page.props.labels[online ? 'connection_online' : 'connection_offline'] }}</span>
    </span>
</template>
