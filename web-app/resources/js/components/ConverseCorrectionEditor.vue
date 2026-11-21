<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, useId } from 'vue';
import FormField from './ui-kit/FormField.vue';
import SlideOver from './ui-kit/SlideOver.vue';

const props = defineProps({
    correction: { type: Object, required: true },
    collections: { type: Array, required: true },
    attachments: { type: Array, required: true },
});
const emit = defineEmits(['close', 'saved']);
const page = usePage();
const labels = computed(() => page.props.labels);
const formId = useId();
const correctionForm = useForm({
    collection_id: props.correction.collection_id ?? null,
    question: props.correction.question ?? '',
    answer: props.correction.answer ?? '',
    follow_up: props.correction.follow_up ?? '',
    catalog_attachment_id: props.correction.catalog_attachment_id ?? null,
});
const correctionAttachments = computed(() => props.attachments.filter((asset) => asset.collection_id === correctionForm.collection_id));

function saveCorrection() {
    const id = props.correction.id;
    const path = '/converse/knowledge/corrections';
    correctionForm.submit(id ? 'patch' : 'post', id ? `${path}/${id}` : path, {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <SlideOver :title="labels.knowledge_train_title" :close-label="labels.close" :busy="correctionForm.processing" @close="emit('close')">
        <form :id="`${formId}-correction`" class="grid gap-3" @submit.prevent="saveCorrection">
            <p class="text-xs text-muted">{{ labels.knowledge_train_help }}</p>
            <FormField v-slot="attrs" :label="labels.knowledge_correction_question" :error="correctionForm.errors.question" required>
                <textarea v-bind="attrs" v-model="correctionForm.question" rows="2" maxlength="500" class="w-full rounded-md border border-line bg-page p-2 text-sm" />
            </FormField>
            <FormField v-slot="attrs" :label="labels.knowledge_correction_answer" :error="correctionForm.errors.answer" required>
                <textarea v-bind="attrs" v-model="correctionForm.answer" rows="4" maxlength="2000" class="w-full rounded-md border border-line bg-page p-2 text-sm" />
            </FormField>
            <FormField v-slot="attrs" :label="labels.knowledge_reply_follow_up" :error="correctionForm.errors.follow_up">
                <textarea v-bind="attrs" v-model="correctionForm.follow_up" rows="2" maxlength="2000" class="w-full rounded-md border border-line bg-page p-2 text-sm" />
            </FormField>
            <FormField v-slot="attrs" :label="labels.knowledge_correction_scope" :error="correctionForm.errors.collection_id">
                <select v-bind="attrs" v-model="correctionForm.collection_id" class="ui-input" :disabled="Boolean(correction.id)"
                    @change="correctionForm.catalog_attachment_id = null">
                    <option :value="null">{{ labels.knowledge_correction_global }}</option>
                    <option v-for="row in collections" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
            </FormField>
            <FormField v-if="correctionAttachments.length || correctionForm.catalog_attachment_id" v-slot="attrs"
                :label="labels.knowledge_reply_attachment" :error="correctionForm.errors.catalog_attachment_id">
                <select v-bind="attrs" v-model="correctionForm.catalog_attachment_id" class="ui-input">
                    <option :value="null">{{ labels.knowledge_reply_no_attachment }}</option>
                    <option v-for="asset in correctionAttachments" :key="asset.id" :value="asset.id">{{ asset.title }}</option>
                </select>
            </FormField>
        </form>
        <template #footer>
            <button type="button" class="ui-button" :disabled="correctionForm.processing" @click="emit('close')">{{ labels.cancel }}</button>
            <button type="submit" :form="`${formId}-correction`" class="ui-button ui-button-primary" :disabled="correctionForm.processing">{{ labels[correctionForm.processing ? 'playground_saving_correction' : 'save'] }}</button>
        </template>
    </SlideOver>
</template>
