<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import FormField from './ui-kit/FormField.vue';

const props = defineProps({ settings: { type: Object, required: true } });
const page = usePage();
const labels = computed(() => page.props.labels);
const providers = { ollama: 'Ollama', openrouter: 'OpenRouter', openai: 'OpenAI', deepseek: 'DeepSeek', anthropic: 'Claude' };
const channels = ['whatsapp', 'messenger'];
const languages = { en: 'English', bn: 'বাংলা', banglish: 'Banglish' };
const conditionFields = ['agent', 'channel', 'language', 'intent', 'complexity'];
const agents = computed(() => Object.fromEntries(Object.entries(props.settings.agents).map(([id, agent]) => [id, agent.name || id])));
const conditionOptions = computed(() => ({
    agent: agents.value,
    channel: Object.fromEntries(channels.map((id) => [id, labels.value[id]])),
    language: languages,
    intent: Object.fromEntries(['inquiry', 'price', 'offer', 'support', 'sales_request'].map((id) => [id, labels.value['workflow_intent_' + id]])),
    complexity: { standard: labels.value.workflow_standard, complex: labels.value.workflow_complex },
}));
function savedWorkflows() {
    return (props.settings.workflows || []).map(({ name, when, steps }) => ({
        name, when: conditions(when), steps: steps.map((step) => ({ agent: null, ...step })),
    }));
}
function conditions(when = {}) {
    return Object.fromEntries(conditionFields.map((field) => [field, when[field] ?? null]));
}

const form = useForm({ workflows: savedWorkflows() });
const selected = ref(form.workflows.length ? 0 : -1);
const workflow = computed(() => form.workflows[selected.value]);
const advancedPreview = ref(false);
const modelChoices = computed(() => {
    const models = {
        ollama: [props.settings.ollama_model, props.settings.ollama_fallback_model,
            ...Object.values(props.settings.agents).flatMap((agent) => [agent.model, agent.fallback_model])],
        openai: [props.settings.openai_model],
    };
    for (const { provider, model } of props.settings.cloud.models) (models[provider] ??= []).push(model);
    return models;
});
const automaticSteps = computed(() => {
    const cloud = props.settings.cloud.models.length ? props.settings.cloud.models : [
        { provider: 'openai', model: props.settings.openai_model, tier: 'economy' },
    ];
    const limits = advancedPreview.value ? { free: 1, economy: 1, advanced: 1 } : { free: 1, economy: 2 };
    const cloudSteps = Object.entries(limits).flatMap(([tier, limit]) => cloud
        .filter((item) => item.tier === tier && item.model)
        .sort((a, b) => Number(isAvailable(b.provider)) - Number(isAvailable(a.provider)))
        .filter((item, index, items) => items.findIndex((other) => other.provider === item.provider && other.model === item.model) === index)
        .slice(0, limit));
    return [...[...new Set([props.settings.ollama_model, props.settings.ollama_fallback_model].filter(Boolean))]
        .map((model) => ({ provider: 'ollama', model })), ...cloudSteps];
});
const steps = computed(() => workflow.value?.steps || automaticSteps.value);
const active = ref(-1);
const stopAt = ref(-1);
const running = ref(false);
const answerFound = computed(() => !running.value && stopAt.value >= 0 && active.value === stopAt.value + 1);
let timer;

function isAvailable(provider) {
    return provider === 'ollama' ? props.settings.ollama_enabled
        : props.settings.allow_cloud && props.settings.cloud.live && props.settings.cloud_providers.includes(provider);
}

function resetPreview() {
    clearTimeout(timer);
    running.value = false;
    active.value = -1;
    stopAt.value = -1;
}

function select(index) {
    resetPreview();
    selected.value = index;
}

function addWorkflow() {
    form.workflows.push({ name: '', when: conditions(), steps: [{ provider: 'ollama', model: props.settings.ollama_model || '', agent: null }] });
    select(form.workflows.length - 1);
}

function move(items, index, by) {
    const next = index + by;
    [items[index], items[next]] = [items[next], items[index]];
    resetPreview();
}

function changeProvider(step) {
    step.model = modelChoices.value[step.provider]?.find(Boolean) || '';
    resetPreview();
}

function preview() {
    clearTimeout(timer);
    active.value = 0;
    running.value = true;
    const advance = () => {
        if ((active.value === stopAt.value + 1 && stopAt.value >= 0) || active.value > steps.value.length) {
            running.value = false;
            return;
        }
        active.value++;
        timer = setTimeout(advance, 650);
    };
    timer = setTimeout(advance, 650);
}

function save() {
    form.patch('/settings/converse/workflows', {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({ workflows: savedWorkflows() });
            form.reset();
            if (selected.value >= form.workflows.length) select(-1);
        },
        onError: (errors) => {
            const match = Object.keys(errors)[0]?.match(/^workflows\.(\d+)\./);
            if (match) select(Number(match[1]));
        },
    });
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <section id="reply-workflows" class="grid scroll-mt-20 gap-4 rounded-lg border border-line bg-surface p-4" aria-labelledby="workflow-title">
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="workflow-title" class="font-semibold">{{ labels.workflow_title }}</h2>
                <p class="mt-1 text-xs text-muted">{{ labels.workflow_help }}</p>
            </div>
            <Link href="/converse/playground" class="ui-button">{{ labels.playground }}</Link>
        </header>

        <form class="grid gap-4 xl:grid-cols-[13rem_minmax(0,1fr)]" @submit.prevent="save">
            <nav :aria-label="labels.workflow_title" class="grid content-start gap-1">
                <button type="button" class="ui-button justify-start" :aria-pressed="selected === -1" @click="select(-1)">{{ labels.workflow_automatic }}</button>
                <button v-for="(rule, index) in form.workflows" :key="index" type="button"
                    class="ui-button justify-start text-start" :aria-pressed="selected === index" @click="select(index)">
                    {{ index + 1 }}. {{ rule.name || labels.workflow_untitled }}
                </button>
                <button type="button" class="ui-button justify-start" :disabled="form.workflows.length >= 8" @click="addWorkflow">{{ labels.workflow_add }}</button>
            </nav>

            <div class="grid min-w-0 gap-4">
                <template v-if="workflow">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="ui-button" :disabled="selected === 0" @click="move(form.workflows, selected, -1); selected--">{{ labels.workflow_move_earlier }}</button>
                        <button type="button" class="ui-button" :disabled="selected === form.workflows.length - 1" @click="move(form.workflows, selected, 1); selected++">{{ labels.workflow_move_later }}</button>
                        <button type="button" class="ui-button text-danger" @click="form.workflows.splice(selected, 1); select(-1)">{{ labels.workflow_remove }}</button>
                    </div>
                    <FormField v-slot="attrs" :label="labels.workflow_name" required>
                        <input v-bind="attrs" v-model="workflow.name" class="ui-input" maxlength="80">
                    </FormField>

                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="mb-2 text-sm font-medium">{{ labels.workflow_conditions }}</legend>
                        <FormField v-for="field in conditionFields" :key="field" v-slot="attrs" :label="labels['workflow_condition_' + field]">
                            <select v-bind="attrs" v-model="workflow.when[field]" class="ui-input">
                                <option :value="null">{{ labels.workflow_any }}</option>
                                <option v-for="(name, id) in conditionOptions[field]" :key="id" :value="id">{{ name }}</option>
                            </select>
                        </FormField>
                    </fieldset>

                    <fieldset class="grid gap-3">
                        <legend class="mb-2 text-sm font-medium">{{ labels.workflow_steps }}</legend>
                        <div v-for="(step, index) in workflow.steps" :key="index" class="grid gap-2 rounded-md border border-line p-3">
                            <div class="grid gap-2 md:grid-cols-3">
                                <FormField v-slot="attrs" :label="labels.workflow_provider" required>
                                    <select v-bind="attrs" v-model="step.provider" class="ui-input" @change="changeProvider(step)">
                                        <option v-for="(name, id) in providers" :key="id" :value="id">{{ name }}</option>
                                    </select>
                                </FormField>
                                <FormField v-slot="attrs" :label="labels.workflow_model" required>
                                    <input v-bind="attrs" v-model="step.model" class="ui-input" :list="'workflow-models-' + index" maxlength="150">
                                    <datalist :id="'workflow-models-' + index">
                                        <option v-for="model in [...new Set((modelChoices[step.provider] || []).filter(Boolean))]" :key="model" :value="model" />
                                    </datalist>
                                </FormField>
                                <FormField v-slot="attrs" :label="labels.workflow_step_agent">
                                    <select v-bind="attrs" v-model="step.agent" class="ui-input">
                                        <option :value="null">{{ labels.workflow_assigned_agent }}</option>
                                        <option v-for="(name, id) in agents" :key="id" :value="id">{{ name }}</option>
                                    </select>
                                </FormField>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="ui-button" :disabled="index === 0" @click="move(workflow.steps, index, -1)">{{ labels.workflow_move_earlier }}</button>
                                <button type="button" class="ui-button" :disabled="index === workflow.steps.length - 1" @click="move(workflow.steps, index, 1)">{{ labels.workflow_move_later }}</button>
                                <button type="button" class="ui-button text-danger" :disabled="workflow.steps.length === 1" @click="workflow.steps.splice(index, 1); resetPreview()">{{ labels.workflow_remove_step }}</button>
                            </div>
                        </div>
                        <button type="button" class="ui-button justify-self-start" :disabled="workflow.steps.length >= 5"
                            @click="workflow.steps.push({ provider: 'openrouter', model: modelChoices.openrouter?.[0] || '', agent: null }); resetPreview()">{{ labels.workflow_add_step }}</button>
                    </fieldset>
                </template>
                <template v-else>
                    <p class="text-sm text-muted">{{ labels.workflow_automatic_help }}</p>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="advancedPreview" type="checkbox" class="accent-brand" @change="resetPreview">
                        {{ labels.workflow_complex }}
                    </label>
                </template>

                <div class="grid gap-3 rounded-lg border border-line bg-page p-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <FormField v-slot="attrs" :label="labels.workflow_preview_outcome">
                            <select v-bind="attrs" v-model.number="stopAt" class="ui-input" :disabled="running" @change="active = -1">
                                <option :value="-1">{{ labels.workflow_all_fail }}</option>
                                <option v-for="(step, index) in steps" :key="index" :value="index" :disabled="!isAvailable(step.provider)">{{ labels.workflow_try }} {{ index + 1 }} · {{ providers[step.provider] }}</option>
                            </select>
                        </FormField>
                        <button type="button" class="ui-button" :disabled="running" @click="preview">{{ labels.workflow_preview }}</button>
                    </div>
                    <ol class="grid content-start gap-2" :aria-label="labels.workflow_preview">
                        <li class="rounded-md border border-line bg-surface px-3 py-2 text-sm" :aria-current="active === 0 ? 'step' : undefined"
                            :class="{ 'ring-2 ring-brand': active === 0 }">{{ labels.workflow_incoming }}</li>
                        <li v-for="(step, index) in steps" :key="index" class="grid gap-1" :aria-current="active === index + 1 ? 'step' : undefined">
                            <p class="ms-3 border-s border-line ps-3 text-xs text-muted">{{ index ? labels.workflow_continue : labels.workflow_first_attempt }}</p>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-line bg-surface px-3 py-2 text-sm transition-shadow duration-300"
                                :class="{ 'ring-2 ring-brand motion-safe:animate-pulse': active === index + 1 && running,
                                    'ring-2 ring-brand': active === index + 1, 'border-brand': answerFound && stopAt === index }">
                                <strong>{{ index + 1 }}. {{ providers[step.provider] }}</strong>
                                <span class="break-all font-mono text-xs">{{ step.model || labels.workflow_choose_model }}</span>
                                <span v-if="step.agent" class="text-xs text-muted">{{ agents[step.agent] || step.agent }}</span>
                                <span v-if="!isAvailable(step.provider)" class="text-xs text-muted">{{ labels.workflow_provider_unavailable }}</span>
                            </div>
                        </li>
                        <li class="grid gap-1" :aria-current="active > steps.length ? 'step' : undefined">
                            <p class="ms-3 border-s border-line ps-3 text-xs text-muted">{{ labels.workflow_when_exhausted }}</p>
                            <div class="rounded-md border border-line bg-surface px-3 py-2 text-sm" :class="{ 'ring-2 ring-brand': active > steps.length }">{{ labels.workflow_human }}</div>
                        </li>
                    </ol>
                    <p class="text-xs text-muted" aria-live="polite">
                        {{ answerFound ? labels.workflow_answer_found : labels.workflow_stop_after_answer }}
                    </p>
                </div>

                <p v-if="form.hasErrors" role="alert" class="text-sm text-danger">{{ Object.values(form.errors)[0] }}</p>
                <button type="submit" class="ui-button ui-button-primary justify-self-start" :disabled="form.processing || !form.isDirty">{{ labels.workflow_save }}</button>
            </div>
        </form>
    </section>
</template>
