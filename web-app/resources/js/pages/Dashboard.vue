<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppIcon from '../components/AppIcon.vue';
import PageHeader from '../components/ui-kit/PageHeader.vue';
import AdminLayout from '../layouts/AdminLayout.vue';
import { formatNumber } from '../lib/format';

defineProps({
    name: { type: String, required: true },
    accountCount: { type: Number, default: null },
});

const page = usePage();
</script>

<template>
    <Head :title="page.props.labels.dashboard" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.dashboard" />
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-lg border border-line bg-surface p-3">
                <p class="text-[13px] text-muted">{{ page.props.labels.signed_in_as }}</p>
                <p class="mt-1 truncate text-base font-semibold" :title="name">{{ name }}</p>
            </section>
            <Link v-if="accountCount !== null" href="/users" class="rounded-lg border border-line bg-surface p-3 hover:border-brand">
                <span class="flex justify-between gap-2 text-[13px] text-muted">
                    {{ page.props.labels.accounts }}
                    <AppIcon name="users" class="text-brand" />
                </span>
                <span class="mt-1 block text-xl font-semibold tabular-nums">{{ formatNumber(accountCount, page.props.locale) }}</span>
            </Link>
        </div>
    </AdminLayout>
</template>
