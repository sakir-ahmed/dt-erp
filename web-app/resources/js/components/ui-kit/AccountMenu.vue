<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, useId } from 'vue';
import AppIcon from '../AppIcon.vue';

const page = usePage();
const menuId = useId();
const account = computed(() => page.props.account);
const initials = computed(() => account.value.name.trim().split(/\s+/u).slice(0, 2)
    .map((part) => Array.from(part)[0]).join('').toLocaleUpperCase());
</script>

<template>
    <div v-if="account">
        <button type="button" class="ui-header-icon flex w-auto gap-1.5 px-1" :popovertarget="menuId" :aria-label="page.props.labels.account">
            <span class="grid size-6 place-items-center rounded-full bg-brand text-[10px] font-semibold text-on-brand" aria-hidden="true">{{ initials }}</span>
            <AppIcon name="chevron-down" class="text-[10px] text-muted" />
        </button>
        <div :id="menuId" popover class="fixed inset-auto end-3 top-12 m-0 w-56 max-w-[calc(100vw-1.5rem)] rounded-lg border border-line bg-surface p-1 text-ink shadow-lg">
            <div class="border-b border-line px-2.5 py-2">
                <p class="wrap-anywhere text-[13px] font-semibold">{{ account.name }}</p>
                <p class="wrap-anywhere text-xs text-muted">{{ account.email }}</p>
                <p v-if="account.roles.length" class="mt-1 wrap-anywhere text-xs text-muted">
                    {{ account.roles.map((role) => page.props.labels[role] ?? role).join(', ') }}
                </p>
            </div>
            <Link href="/logout" method="post" as="button" class="ui-button mt-1 w-full !justify-start border-transparent">
                {{ page.props.labels.sign_out }}
            </Link>
        </div>
    </div>
</template>
