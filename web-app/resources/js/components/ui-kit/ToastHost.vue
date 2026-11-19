<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Toaster } from 'vue-sonner';

const desktop = window.matchMedia('(min-width: 768px)');
const position = ref(desktop.matches ? 'top-right' : 'top-center');
const theme = ref('light');
const themeObserver = new MutationObserver(updateTheme);

function updateTheme() {
    theme.value = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

function updatePosition() {
    position.value = desktop.matches ? 'top-right' : 'top-center';
}

onMounted(() => {
    updateTheme();
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    desktop.addEventListener('change', updatePosition);
});

onBeforeUnmount(() => {
    themeObserver.disconnect();
    desktop.removeEventListener('change', updatePosition);
});
</script>

<template>
    <Toaster :theme="theme" :position="position" :duration="3000" :visible-toasts="3" close-button />
</template>
