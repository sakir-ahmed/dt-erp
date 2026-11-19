<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import AppIcon from '../AppIcon.vue';

defineProps({
    title: { type: String, required: true },
    closeLabel: { type: String, required: true },
    busy: Boolean,
});

const emit = defineEmits(['close']);
const dialog = ref(null);
const titleId = useId();

onMounted(() => dialog.value.showModal());
onBeforeUnmount(() => dialog.value.close());
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            :aria-labelledby="titleId"
            :aria-busy="busy"
            class="ui-slide-over fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-none bg-surface p-0 text-ink shadow-xl backdrop:bg-black/40 backdrop:backdrop-blur-sm sm:w-[40%] sm:min-w-[420px] sm:border-l sm:border-line"
            @cancel.prevent="!busy && dialog.close()"
            @click.self="!busy && dialog.close()"
            @close="emit('close')"
        >
            <div class="flex h-full flex-col">
                <header class="flex h-12 shrink-0 items-center justify-between gap-3 border-b border-line px-4">
                    <h2 :id="titleId" class="min-w-0 truncate text-base font-semibold">{{ title }}</h2>
                    <button type="button" class="ui-button ui-button-icon border-transparent text-muted" :aria-label="closeLabel" :disabled="busy" @click="dialog.close()">
                        <AppIcon name="close" />
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-4">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="flex shrink-0 items-center justify-end gap-2 border-t border-line p-4">
                    <slot name="footer" />
                </footer>
            </div>
        </dialog>
    </Teleport>
</template>
