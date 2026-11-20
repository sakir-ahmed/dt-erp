<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    locale: { type: String, required: true },
});

const now = ref(new Date());
const locale = computed(() => props.locale === 'bn' ? 'bn-BD' : 'en-BD');
const dateFormat = computed(() => new Intl.DateTimeFormat(locale.value, {
    weekday: 'short', month: 'short', day: 'numeric', timeZone: 'Asia/Dhaka',
}));
const timeFormat = computed(() => new Intl.DateTimeFormat(locale.value, {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Asia/Dhaka',
}));

let timer;

function tick() {
    now.value = new Date();
    timer = window.setTimeout(tick, 1000 - Date.now() % 1000);
}

onMounted(tick);
onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <time :datetime="now.toISOString()" class="mx-1 hidden flex-col items-end border-e border-line pe-2.5 leading-tight lg:flex">
        <span class="text-[10px] text-muted">{{ dateFormat.format(now) }}</span>
        <span class="text-[13px] font-semibold tabular-nums">{{ timeFormat.format(now) }}</span>
    </time>
</template>
