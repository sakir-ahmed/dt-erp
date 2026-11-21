<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AppIcon from '../AppIcon.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    placeholder: { type: String, default: '' },
    disabled: Boolean,
    maxlength: { type: Number, default: 4096 },
    loadSavedValue: { type: Function, default: null },
});
const emit = defineEmits(['update:modelValue']);

const page = usePage();
const revealed = ref(false);
const savedValue = ref('');
const busy = ref(false);
const displayValue = computed(() => props.modelValue || (revealed.value ? savedValue.value : ''));

watch([() => props.disabled, () => Boolean(props.loadSavedValue)], () => {
    revealed.value = false;
    savedValue.value = '';
});

function updateValue(event) {
    savedValue.value = '';
    emit('update:modelValue', event.target.value);
}

async function toggleReveal() {
    if (revealed.value) {
        revealed.value = false;
        savedValue.value = '';
        return;
    }
    if (props.modelValue || !props.loadSavedValue) {
        revealed.value = true;
        return;
    }
    busy.value = true;
    try {
        const value = await props.loadSavedValue('reveal');
        if (!props.disabled && props.loadSavedValue && !props.modelValue) {
            savedValue.value = value;
            revealed.value = true;
        }
    } catch {
        toast.error(page.props.labels.load_secret_failed);
    } finally {
        busy.value = false;
    }
}

async function copy() {
    if (busy.value || (!displayValue.value && !props.loadSavedValue)) return;
    busy.value = true;
    try {
        if (displayValue.value) {
            await navigator.clipboard.writeText(displayValue.value);
        } else if (navigator.clipboard?.write && typeof ClipboardItem === 'function') {
            const value = props.loadSavedValue('copy');
            const item = new ClipboardItem({ 'text/plain': value.then((secret) => new Blob([secret], { type: 'text/plain' })) });
            await Promise.all([value, navigator.clipboard.write([item])]);
        } else {
            await navigator.clipboard.writeText(await props.loadSavedValue('copy'));
        }
        toast.success(page.props.labels.copied_secret);
    } catch {
        toast.error(page.props.labels.copy_secret_failed);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <span class="flex min-w-0 items-stretch gap-1">
        <input
            v-bind="$attrs"
            :value="displayValue"
            :type="revealed ? 'text' : 'password'"
            :placeholder="placeholder"
            :disabled="disabled"
            :maxlength="maxlength"
            autocomplete="new-password"
            class="ui-input min-w-0 flex-1 placeholder:text-muted"
            @focus="savedValue && $event.target.select()"
            @input="updateValue"
        >
        <button
            type="button"
            class="ui-button ui-button-icon shrink-0"
            :aria-label="page.props.labels[revealed ? 'hide_secret' : 'show_secret']"
            :title="page.props.labels[revealed ? 'hide_secret' : 'show_secret']"
            :disabled="disabled || busy"
            :aria-pressed="revealed"
            @click="toggleReveal"
        >
            <AppIcon :name="revealed ? 'eye-slash' : 'eye'" />
        </button>
        <button
            type="button"
            class="ui-button ui-button-icon shrink-0"
            :aria-label="page.props.labels.copy_secret"
            :title="page.props.labels.copy_secret"
            :disabled="disabled || busy || (!displayValue && !loadSavedValue)"
            @click="copy"
        >
            <AppIcon name="copy" />
        </button>
    </span>
</template>
