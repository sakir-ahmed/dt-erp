<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';

const page = usePage();
const language = useForm({ locale: page.props.locale });
const dark = ref(document.documentElement.classList.contains('dark'));

function switchLanguage() {
    language.locale = page.props.locale === 'en' ? 'bn' : 'en';
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
    <div class="flex items-center gap-2">
        <button
            type="button"
            class="ui-button"
            :aria-label="page.props.labels.language"
            :disabled="language.processing"
            @click="switchLanguage"
        >
            <span :lang="page.props.locale === 'en' ? 'bn' : 'en'">{{ page.props.locale === 'en' ? 'বাংলা' : 'English' }}</span>
        </button>
        <button
            type="button"
            class="ui-button ui-button-icon"
            :aria-label="page.props.labels.theme"
            :aria-pressed="dark"
            @click="toggleTheme"
        >
            <AppIcon name="moon" />
        </button>
    </div>
</template>
