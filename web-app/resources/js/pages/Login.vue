<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import FormField from '../components/ui-kit/FormField.vue';
import WorkspacePreferences from '../components/WorkspacePreferences.vue';

const props = defineProps({
    localQuickSignInUsers: { type: Array, default: () => [] },
    quickSignInPath: { type: String, default: '/login/local' },
});

const page = usePage();
const localSignInForm = useForm({ user_id: null });
function signInAs(userId) {
    localSignInForm.user_id = userId;
    localSignInForm.post(props.quickSignInPath);
}
const form = useForm({ email: '', password: '' });
const emailInput = ref(null);
const passwordInput = ref(null);
const showPassword = ref(false);

function signIn() {
    form.post('/login', {
        onFinish: () => {
            form.reset('password');
            showPassword.value = false;
        },
        onError: async (errors) => {
            await nextTick();
            (errors.email ? emailInput.value : passwordInput.value)?.focus();
        },
    });
}
</script>

<template>
    <Head :title="page.props.labels.sign_in" />
    <div class="min-h-dvh p-3 sm:p-4 lg:p-5">
        <main class="mx-auto grid min-h-[calc(100dvh-1.5rem)] overflow-hidden rounded-xl border border-line bg-surface sm:min-h-[calc(100dvh-2rem)] lg:min-h-[calc(100dvh-2.5rem)] lg:grid-cols-[minmax(280px,0.86fr)_minmax(0,1.14fr)]">
            <div class="relative isolate flex flex-col justify-between overflow-hidden bg-brand p-6 text-on-brand sm:p-8 lg:p-10">
                <div class="pointer-events-none absolute -right-36 -bottom-44 -z-10 size-96 rounded-full border border-current opacity-20" aria-hidden="true" />
                <div class="pointer-events-none absolute -right-20 -bottom-28 -z-10 size-80 rounded-full border border-current opacity-20" aria-hidden="true" />

                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-lg border border-current">
                        <AppIcon name="cubes" />
                    </span>
                    <span class="font-display text-sm font-semibold">DT ERP</span>
                </div>

                <p class="mt-8 max-w-sm font-display text-2xl leading-snug font-semibold sm:text-3xl">
                    {{ page.props.labels.sign_in_brand }}
                </p>
                <p class="mt-6 text-xs lg:mt-0">Datatech BD</p>
            </div>

            <section class="flex min-w-0 flex-col p-4 sm:p-6">
                <div class="flex justify-end">
                    <WorkspacePreferences />
                </div>
                <div class="flex flex-1 flex-col items-center justify-center gap-5 px-2 py-8 sm:p-10">
                    <form class="w-full max-w-sm space-y-4" @submit.prevent="signIn">
                        <div>
                            <h1 class="font-display text-xl font-semibold">{{ page.props.labels.sign_in }}</h1>
                            <p class="mt-1 text-[13px] text-muted">{{ page.props.labels.sign_in_help }}</p>
                        </div>

                        <FormField v-slot="inputAttributes" :label="page.props.labels.email" :error="form.errors.email">
                            <input
                                ref="emailInput"
                                v-bind="inputAttributes"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                required
                                maxlength="254"
                                class="ui-input"
                            >
                        </FormField>

                        <FormField v-slot="inputAttributes" :label="page.props.labels.password" :error="form.errors.password">
                            <div class="relative">
                                <input
                                    ref="passwordInput"
                                    v-bind="inputAttributes"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="current-password"
                                    required
                                    maxlength="1024"
                                    class="ui-input pe-9"
                                >
                                <button
                                    type="button"
                                    class="absolute inset-y-0 end-0 grid w-9 place-items-center rounded-e-md text-muted hover:text-ink"
                                    :aria-label="page.props.labels[showPassword ? 'hide_password' : 'show_password']"
                                    @click="showPassword = !showPassword"
                                >
                                    <AppIcon :name="showPassword ? 'eye-slash' : 'eye'" />
                                </button>
                            </div>
                        </FormField>

                        <button type="submit" :disabled="form.processing || localSignInForm.processing" class="ui-button ui-button-primary w-full">
                            {{ page.props.labels.sign_in }}
                        </button>
                    </form>

                    <section
                        v-if="localQuickSignInUsers.length"
                        class="w-full max-w-sm border-t border-line pt-4"
                        aria-labelledby="local-sign-in-title"
                    >
                        <h2 id="local-sign-in-title" class="text-sm font-semibold">{{ page.props.labels.local_quick_sign_in }}</h2>
                        <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                            <li v-for="user in localQuickSignInUsers" :key="user.id">
                                <button
                                    type="button"
                                    class="ui-button h-auto min-h-9 w-full !justify-start py-1.5 text-start"
                                    :disabled="localSignInForm.processing || form.processing"
                                    @click="signInAs(user.id)"
                                >
                                    <AppIcon name="users" />
                                    <span class="min-w-0 wrap-anywhere">{{ user.name }}</span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </section>
        </main>
    </div>
</template>
