<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';

defineProps({ runbook: { type: String, required: true } });

const page = usePage();
</script>

<template>
    <Head :title="page.props.labels.converse_development" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.converse_development">
            <template #actions>
                <Link class="ui-button" href="/converse/playground">{{ page.props.labels.playground }}</Link>
            </template>
        </PageHeader>

        <article
            class="max-w-4xl space-y-4 rounded-lg border border-line bg-surface p-5 text-sm leading-relaxed
                [&_a]:text-brand [&_a]:underline [&_h1]:font-display [&_h1]:text-xl [&_h1]:font-semibold
                [&_h2]:mt-7 [&_h2]:border-t [&_h2]:border-line [&_h2]:pt-4 [&_h2]:text-base [&_h2]:font-semibold
                [&_li]:mb-2 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6
                [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-page [&_pre]:p-3"
            v-html="runbook"
        />
    </AdminLayout>
</template>
