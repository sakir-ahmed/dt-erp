<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';

const page = usePage();
const language = useForm({ locale: page.props.locale });
const dark = ref(document.documentElement.classList.contains('dark'));

function switchLanguage(locale) {
    if (locale === page.props.locale || language.processing) {
        return;
    }

    language.locale = locale;
    language.post('/locale', { preserveScroll: true });
}

function toggleTheme() {
    dark.value = !dark.value;
    document.documentElement.classList.toggle('dark', dark.value);

    try {
        localStorage.setItem('theme', dark.value ? 'dark' : 'light');
    } catch {
        // The current page can still use the selected theme.
    }
}
</script>

<template>
    <div class="flex items-center gap-1">
        <div class="flex items-center rounded-md border border-line bg-surface p-0.5" role="group" :aria-label="page.props.labels.language_selector">
            <button
                v-for="code in ['en', 'bn']"
                :key="code"
                type="button"
                :lang="code"
                :aria-label="code === 'en' ? 'English' : 'বাংলা'"
                :aria-pressed="page.props.locale === code"
                :disabled="language.processing"
                class="h-5 cursor-pointer rounded px-1.5 text-[11px] font-medium transition-colors disabled:opacity-60"
                :class="page.props.locale === code ? 'bg-brand text-on-brand' : 'text-muted hover:text-ink'"
                @click="switchLanguage(code)"
            >
                {{ code === 'en' ? 'EN' : 'বাং' }}
            </button>
        </div>
        <button type="button" class="ui-header-icon" :aria-label="page.props.labels.theme" :aria-pressed="dark" @click="toggleTheme">
            <AppIcon :name="dark ? 'sun' : 'moon'" />
        </button>
    </div>
</template>
