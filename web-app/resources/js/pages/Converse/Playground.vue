<script setup>
import { Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import ConverseCorrectionEditor from '../../components/ConverseCorrectionEditor.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

const props = defineProps({
    samples: { type: Object, required: true },
    cloudAvailable: Boolean,
    cloudProviders: { type: [Object, Array], required: true },
    ollamaAvailable: Boolean,
    sandboxAvailable: Boolean,
    knowledgeSamples: { type: Object, required: true },
    channels: { type: Array, required: true },
    collections: { type: Array, required: true },
    initialCollection: { type: Number, default: null },
    canTrain: Boolean,
    correctionAttachments: { type: Array, required: true },
});

const page = usePage();
const labels = computed(() => page.props.labels);
const form = useHttp({
    mode: props.collections.length ? 'knowledge' : 'routing',
    language: 'en', provider_mode: props.ollamaAvailable || !props.cloudAvailable ? 'local' : 'cloud',
    cloud_provider: null, complex: false, sandbox: false, message: '', channel: null, collection: props.initialCollection, history: [],
});
const outcome = ref(null);
const failed = ref(false);
const chatTurns = ref([]);
const pendingMessage = ref('');
const chatBox = ref(null);
const chatInput = ref(null);
const replyDetails = ref(null);
const correction = ref(null);
const draft = computed(() => outcome.value?.draft);
const knowledgeAvailable = computed(() => props.collections.length > 0);
const selectedChannel = computed(() => props.channels.find(({ id }) => id === form.channel));
const availableCollections = computed(() => selectedChannel.value
    ? props.collections.filter(({ id }) => selectedChannel.value.collections.includes(id))
    : props.collections);
const canRun = computed(() => {
    if (form.mode === 'knowledge') return form.message.trim().length >= 2 && availableCollections.value.length > 0;
    if (form.sandbox || form.provider_mode === 'local') return props.ollamaAvailable;
    return props.cloudAvailable;
});
const scope = computed(() => {
    if (!draft.value?.scope) return null;
    const { label, type } = draft.value.scope;
    return label || page.props.labels[type === 'none' ? 'playground_no_scope' : 'knowledge_chat_all_collections'];
});

function changeMode(mode) {
    if (mode === form.mode) return;
    form.mode = mode;
    outcome.value = mode === 'knowledge' ? chatTurns.value.at(-1)?.result ?? null : null;
    failed.value = false;
    form.clearErrors();
}

function newChat() {
    chatTurns.value = [];
    form.message = '';
    outcome.value = null;
    failed.value = false;
    form.clearErrors();
    chatInput.value?.focus();
}

function train(turn) {
    const scope = turn.result.draft.scope?.collection_ids ?? [];
    correction.value = {
        question: turn.question.slice(0, 500),
        collection_id: form.collection ?? (scope.length === 1 ? scope[0] : null),
    };
}

function showReplyDetails(turn) {
    outcome.value = turn.result;
    replyDetails.value?.focus();
}

async function scrollChat() {
    await nextTick();
    chatBox.value?.scrollTo({ top: chatBox.value.scrollHeight });
}

async function run() {
    if (!canRun.value || form.processing) return;
    const knowledge = form.mode === 'knowledge';
    failed.value = false;
    form.clearErrors();
    if (knowledge) {
        pendingMessage.value = form.message.trim();
        form.history = chatTurns.value.slice(-5).flatMap(({ question, result }) => [
            { direction: 'in', body: question },
            { direction: 'out', body: result.draft.messages.join('\n\n').slice(0, 2000) },
        ]);
    } else {
        outcome.value = null;
    }

    try {
        const request = form.post('/converse/playground');
        if (knowledge) void scrollChat();
        const result = await request;
        outcome.value = result;
        if (knowledge) {
            chatTurns.value.push({ question: pendingMessage.value, result });
            form.message = '';
        }
    } catch {
        failed.value = true;
    } finally {
        pendingMessage.value = '';
        if (knowledge) {
            await scrollChat();
            chatInput.value?.focus();
        }
    }
}
</script>

<template>
    <AdminLayout>
        <Head :title="labels.playground" />
        <PageHeader :title="labels.playground">
            <template #actions>
                <Link v-if="page.props.canViewConverseAiCalls" href="/converse/ai-calls" class="ui-button">
                    <AppIcon name="chart-line" />{{ labels.ai_call_history }}
                </Link>
                <Link v-if="page.props.canManageSettings" href="/settings#converse-ai" class="ui-button">
                    <AppIcon name="settings" />{{ labels.settings_converse_ai }}
                </Link>
            </template>
        </PageHeader>

        <div v-if="knowledgeAvailable" class="mb-4 grid gap-2 sm:grid-cols-2" :aria-label="labels.playground_test_mode" role="group">
            <button v-for="mode in ['knowledge', 'routing']" :key="mode" type="button"
                class="flex min-h-16 cursor-pointer items-start gap-3 rounded-xl border px-4 py-3 text-start motion-safe:transition-colors disabled:cursor-default"
                :class="form.mode === mode ? 'border-brand bg-selected' : 'border-line bg-surface hover:bg-page'"
                :aria-pressed="form.mode === mode" :disabled="form.processing" @click="changeMode(mode)">
                <AppIcon :name="mode === 'knowledge' ? 'book' : 'plug'" class="mt-0.5 shrink-0 text-brand" />
                <span>
                    <span class="block text-sm font-semibold">{{ labels[mode === 'knowledge' ? 'knowledge_chat_title' : 'playground_model_routing'] }}</span>
                    <span class="mt-0.5 block text-xs text-muted">{{ labels[mode === 'knowledge' ? 'playground_chat_mode_help' : 'playground_routing_mode_help'] }}</span>
                </span>
            </button>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section v-if="form.mode === 'knowledge'" class="min-w-0 rounded-xl border border-line bg-surface" :aria-label="labels.knowledge_chat_title">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3 sm:px-5">
                    <p class="max-w-sm text-xs text-muted">{{ labels.playground_local_knowledge }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Link v-if="page.props.canManageConverseKnowledge" :href="form.collection ? `/converse/knowledge?collection=${form.collection}` : '/converse/knowledge'" class="ui-button">{{ labels.knowledge }}</Link>
                        <button type="button" class="ui-button" :disabled="form.processing || !chatTurns.length" @click="newChat">
                            <AppIcon name="add" />{{ labels.playground_new_chat }}
                        </button>
                    </div>
                </header>
                <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 sm:px-5">
                    <FormField v-if="channels.length" v-slot="attrs" :label="labels.channel" :error="form.errors.channel">
                        <select v-bind="attrs" v-model="form.channel" class="ui-input min-h-9"
                            :disabled="form.processing || chatTurns.length > 0" @change="form.collection = null; form.clearErrors()">
                            <option :value="null">{{ labels.playground_default_routing }}</option>
                            <option v-for="channel in channels" :key="channel.id" :value="channel.id">{{ channel.name }} · {{ labels[channel.type] }}</option>
                        </select>
                    </FormField>
                    <FormField v-slot="attrs" :label="labels.knowledge_chat_scope" :error="form.errors.collection">
                        <select v-bind="attrs" v-model="form.collection" class="ui-input min-h-9"
                            :disabled="form.processing || chatTurns.length > 0 || availableCollections.length === 0" @change="form.clearErrors()">
                            <option :value="null">{{ labels.knowledge_chat_automatic }}</option>
                            <option v-for="collection in availableCollections" :key="collection.id" :value="collection.id">{{ collection.name }}</option>
                        </select>
                    </FormField>
                    <p v-if="!availableCollections.length" class="text-xs text-muted sm:col-span-2">{{ labels.playground_collection_unavailable }}</p>
                    <p v-else-if="chatTurns.length" class="text-xs text-muted sm:col-span-2">{{ labels.playground_scope_locked }}</p>
                </div>

                <div ref="chatBox" role="log" :aria-label="labels.playground_transcript" class="min-h-56 max-h-[min(45vh,32rem)] space-y-4 overflow-y-auto p-4 sm:px-5">
                    <EmptyState v-if="!chatTurns.length && !pendingMessage" icon="play" :title="labels.knowledge_chat_empty" />
                    <div v-for="(turn, index) in chatTurns" :key="index" class="grid gap-2">
                        <p class="ms-auto max-w-[90%] rounded-xl bg-selected px-3 py-2 text-sm whitespace-pre-wrap wrap-anywhere">{{ turn.question }}</p>
                        <div class="w-fit max-w-[95%] space-y-2 rounded-xl border border-line bg-page p-3">
                            <p v-for="(message, part) in turn.result.draft.messages" :key="part" class="text-sm whitespace-pre-wrap wrap-anywhere">{{ message }}</p>
                            <p v-if="turn.result.draft.attachment" class="flex items-center gap-2 text-xs text-muted">
                                <AppIcon name="paperclip" />{{ turn.result.draft.attachment }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-1 text-xs font-medium"
                                    :class="turn.result.draft.decision === 'send' ? 'bg-success/10 text-success' : 'border border-line text-muted'">
                                    {{ labels[turn.result.draft.decision === 'send' ? 'knowledge_chat_send' : 'playground_would_handoff'] }}
                                </span>
                                <button type="button" class="ui-button" :aria-pressed="outcome === turn.result" @click="showReplyDetails(turn)">
                                    {{ labels.playground_reply_details }}
                                </button>
                                <button v-if="canTrain && !turn.result.draft.correction" type="button" class="ui-button" :disabled="form.processing" @click="train(turn)">
                                    <AppIcon name="edit" />{{ labels.knowledge_train_answer }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="pendingMessage" class="grid gap-2">
                        <p class="ms-auto max-w-[90%] rounded-xl bg-selected px-3 py-2 text-sm whitespace-pre-wrap wrap-anywhere">{{ pendingMessage }}</p>
                        <p class="text-sm text-muted">{{ labels.playground_running }}</p>
                    </div>
                </div>

                <form class="grid gap-2 border-t border-line p-4 sm:px-5" @submit.prevent="run">
                    <FormField v-slot="attrs" :label="labels.playground_test_message" :error="form.errors.message" required>
                        <textarea ref="chatInput" v-bind="attrs" v-model="form.message" rows="2" maxlength="2000"
                            class="block w-full rounded-md border border-line bg-page p-2.5 text-sm" :placeholder="labels.knowledge_chat_placeholder" :disabled="form.processing" />
                    </FormField>
                    <p v-if="failed && !Object.keys(form.errors).length" role="alert" class="text-xs text-danger">{{ labels.knowledge_chat_failed }}</p>
                    <button type="submit" class="ui-button ui-button-primary min-h-9 justify-self-end" :disabled="form.processing || !canRun">
                        <AppIcon name="play" />{{ labels[form.processing ? 'playground_running' : 'knowledge_chat_ask'] }}
                    </button>
                </form>
            </section>

            <form v-else class="grid min-w-0 gap-3 rounded-xl border border-line bg-surface p-4 sm:p-5" @submit.prevent="run">
                <h2 class="font-semibold">{{ labels.playground_model_routing }}</h2>
                <p class="text-sm text-muted">{{ labels.playground_help }}</p>
                <FormField v-slot="attrs" :label="page.props.labels.language_selector">
                    <select v-bind="attrs" v-model="form.language" class="ui-input" :disabled="form.processing">
                        <option value="en">English</option>
                        <option value="bn" lang="bn">বাংলা</option>
                        <option value="banglish">Banglish</option>
                    </select>
                </FormField>
                <p class="rounded-md bg-page p-3 text-sm">{{ (form.sandbox ? knowledgeSamples : samples)[form.language] }}</p>
                <label v-if="sandboxAvailable" class="flex items-center gap-2 text-sm">
                    <input v-model="form.sandbox" type="checkbox" class="accent-brand" :disabled="form.processing">
                    {{ page.props.labels.ai_sandbox_knowledge }}
                </label>
                <FormField v-if="cloudAvailable && !form.sandbox" v-slot="attrs" :label="page.props.labels.playground_provider_mode">
                    <select v-bind="attrs" v-model="form.provider_mode" class="ui-input" :disabled="form.processing">
                        <option v-if="ollamaAvailable" value="local">{{ page.props.labels.playground_local }}</option>
                        <option value="configured">{{ page.props.labels.playground_configured_routing }}</option>
                        <option value="cloud">{{ page.props.labels.playground_cloud_only }}</option>
                    </select>
                </FormField>
                <FormField v-if="cloudAvailable && form.provider_mode === 'cloud' && !form.sandbox" v-slot="attrs" :label="page.props.labels.playground_cloud_provider">
                    <select v-bind="attrs" v-model="form.cloud_provider" class="ui-input" :disabled="form.processing">
                        <option :value="null">{{ page.props.labels.playground_cloud_all }}</option>
                        <option v-for="(name, id) in cloudProviders" :key="id" :value="id">{{ name }}</option>
                    </select>
                </FormField>
                <label v-if="cloudAvailable && !form.sandbox && form.provider_mode !== 'local'" class="flex items-center gap-2 text-sm">
                    <input v-model="form.complex" type="checkbox" class="accent-brand" :disabled="form.processing">
                    {{ page.props.labels.ai_test_advanced }}
                </label>
                <p v-if="!ollamaAvailable && !cloudAvailable" class="text-sm text-muted">{{ page.props.labels.playground_no_provider }}</p>
                <button type="submit" class="ui-button ui-button-primary min-h-9 justify-self-start" :disabled="form.processing || !canRun">
                    <AppIcon name="play" />{{ labels[form.processing ? 'playground_running' : 'playground_run'] }}
                </button>
            </form>

            <section class="min-w-0 self-start rounded-xl border border-line bg-surface p-4 sm:p-5" aria-labelledby="playground-result">
                <h2 id="playground-result" ref="replyDetails" tabindex="-1" class="font-semibold">{{ labels[form.mode === 'knowledge' ? 'playground_reply_details' : 'playground_result'] }}</h2>
                <div class="mt-3 grid gap-3 text-sm" role="status" aria-live="polite">
                    <template v-if="draft">
                        <dl class="flex flex-wrap gap-4 text-xs">
                            <div v-if="draft.decision">
                                <dt class="text-muted">{{ page.props.labels.playground_decision }}</dt>
                                <dd class="font-medium">{{ page.props.labels[draft.decision === 'send' ? 'knowledge_chat_send' : 'playground_would_handoff'] }}</dd>
                            </div>
                            <div v-if="draft.provider">
                                <dt class="text-muted">{{ page.props.labels.playground_provider }}</dt>
                                <dd class="font-medium wrap-anywhere">{{ [cloudProviders[draft.provider] || draft.provider, draft.model].filter(Boolean).join(' · ') }}<span v-if="draft.fallback"> · {{ page.props.labels.playground_fallback }}</span></dd>
                            </div>
                            <div v-if="scope">
                                <dt class="text-muted">{{ page.props.labels.knowledge_chat_scope }}</dt>
                                <dd class="font-medium">{{ scope }}</dd>
                            </div>
                            <div v-if="draft.language">
                                <dt class="text-muted">{{ labels.settings_converse_reply_language }}</dt>
                                <dd class="font-medium">{{ labels[`settings_converse_reply_language_${draft.language}`] ?? 'Banglish' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted">{{ page.props.labels.playground_duration }}</dt>
                                <dd class="font-medium">{{ (outcome.duration_ms / 1000).toLocaleString(page.props.locale, { maximumFractionDigits: 2 }) }} {{ page.props.labels.playground_seconds }}</dd>
                            </div>
                        </dl>
                        <template v-if="form.mode === 'routing'">
                            <p v-for="(message, index) in draft.messages ?? [draft.text]" :key="index" class="rounded-md bg-page p-3 whitespace-pre-wrap wrap-anywhere">{{ message }}</p>
                        </template>
                        <div v-if="draft.sources?.length">
                            <p class="text-xs font-medium">{{ page.props.labels.playground_sources }}</p>
                            <ul class="list-disc ps-4 text-xs text-muted">
                                <li v-for="source in draft.sources" :key="source.id">{{ source.title }}</li>
                            </ul>
                        </div>
                        <p v-if="draft.correction" class="text-xs font-medium text-brand">{{ labels.knowledge_correction_badge }}</p>
                        <p v-if="draft.attachment" class="text-xs text-muted">{{ page.props.labels.playground_attachment }} {{ draft.attachment }}</p>
                    </template>
                    <p v-else class="text-muted">
                        {{ page.props.labels[form.processing ? 'playground_running' : failed ? 'knowledge_chat_failed' : outcome ? 'playground_unavailable' : 'playground_result_empty'] }}
                    </p>
                </div>
                <section v-if="outcome?.attempts?.length" class="mt-4 border-t border-line pt-3" aria-labelledby="playground-attempts">
                    <h3 id="playground-attempts" class="font-medium">{{ page.props.labels.workflow_steps }}</h3>
                    <p v-if="outcome.attempts[0].workflow" class="my-2 text-sm text-muted">{{ outcome.attempts[0].workflow }}</p>
                    <ol class="mt-2 grid gap-2">
                        <li v-for="(attempt, index) in outcome.attempts" :key="index"
                            class="grid gap-1 rounded-md border border-line bg-page p-2 text-xs">
                            <span class="font-medium">{{ index + 1 }}. {{ cloudProviders[attempt.provider] || attempt.provider }} · {{ attempt.model }}</span>
                            <span class="text-muted">
                                    <span v-if="attempt.operation === 'translation'">{{ labels.ai_calls.translation }} · </span>
                                {{ page.props.labels[attempt.reason ? 'workflow_skip_' + attempt.reason : 'workflow_attempt_' + attempt.status] }}
                                <span v-if="attempt.agent"> · {{ attempt.agent }}</span>
                                <span v-if="attempt.duration_ms !== undefined"> · {{ (attempt.duration_ms / 1000).toLocaleString(page.props.locale, { maximumFractionDigits: 2 }) }} {{ page.props.labels.playground_seconds }}</span>
                            </span>
                        </li>
                    </ol>
                </section>
            </section>
        </div>

        <ConverseCorrectionEditor v-if="correction" :correction="correction" :collections="collections" :attachments="correctionAttachments"
            @close="correction = null" @saved="correction = null" />
    </AdminLayout>
</template>
