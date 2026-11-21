<script setup>
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import AppIcon from '../AppIcon.vue';

defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: { type: [String, null], default: '' },
    placeholder: { type: String, default: '' },
    disabled: Boolean,
    maxlength: { type: Number, default: 4096 },
});
const emit = defineEmits(['update:modelValue']);

const page = usePage();
const revealed = ref(false);

async function copy(value) {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        toast.success(page.props.labels.copied_secret);
    } catch {
        // Clipboard permission can be unavailable; nothing to recover.
    }
}
</script>

<template>
    <span class="flex min-w-0 items-stretch gap-1">
        <input
            v-bind="$attrs"
            :value="modelValue"
            :type="revealed ? 'text' : 'password'"
            :placeholder="placeholder"
            :disabled="disabled"
            :maxlength="maxlength"
            autocomplete="new-password"
            class="ui-input min-w-0 flex-1 placeholder:text-muted"
            @input="emit('update:modelValue', $event.target.value)"
        >
        <button
            type="button"
            class="ui-button ui-button-icon shrink-0"
            :aria-label="page.props.labels[revealed ? 'hide_secret' : 'show_secret']"
            :title="page.props.labels[revealed ? 'hide_secret' : 'show_secret']"
            :disabled="disabled"
            :aria-pressed="revealed"
            @click="revealed = !revealed"
        >
            <AppIcon :name="revealed ? 'eye-slash' : 'eye'" />
        </button>
        <button
            type="button"
            class="ui-button ui-button-icon shrink-0"
            :aria-label="page.props.labels.copy_secret"
            :title="page.props.labels.copy_secret"
            :disabled="disabled || !modelValue"
            @click="copy(modelValue)"
        >
            <AppIcon name="copy" />
        </button>
    </span>
</template>
