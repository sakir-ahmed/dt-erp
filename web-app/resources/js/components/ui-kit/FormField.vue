<script setup>
import { useId } from 'vue';
import AppIcon from '../AppIcon.vue';

defineProps({
    label: { type: String, required: true },
    error: { type: String, default: '' },
    required: Boolean,
});

const id = useId();
</script>

<template>
    <div class="min-w-0">
        <label :for="id" class="mb-1 block text-xs font-medium">
            {{ label }}<span v-if="required" class="ms-1 text-danger" aria-hidden="true">*</span>
        </label>
        <slot
            :id="id"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id}-error` : undefined"
        />
        <p v-if="error" :id="`${id}-error`" role="alert" class="mt-1 flex items-center gap-1 text-xs text-danger">
            <AppIcon name="warning" />
            {{ error }}
        </p>
    </div>
</template>
