<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../components/ui-kit/EmptyState.vue';
import AdminLayout from '../layouts/AdminLayout.vue';

const props = defineProps({
    status: { type: Number, required: true },
    returnUrl: { type: String, required: true },
});

const page = usePage();
const title = computed(() => props.status === 403 ? page.props.labels.forbidden_title : page.props.labels.missing_title);
const description = computed(() => props.status === 403 ? page.props.labels.forbidden_body : page.props.labels.missing_body);
</script>

<template>
    <Head :title="title" />
    <component :is="page.props.signedIn ? AdminLayout : 'main'">
        <div class="mx-auto max-w-lg rounded-lg border border-line bg-surface pb-4 text-center" :class="{ 'mt-12': !page.props.signedIn }">
            <EmptyState icon="warning" :title="title" :description="description" />
            <Link :href="returnUrl" class="ui-button ui-button-primary">
                {{ page.props.signedIn ? page.props.labels.return_dashboard : page.props.labels.return_login }}
            </Link>
        </div>
    </component>
</template>
