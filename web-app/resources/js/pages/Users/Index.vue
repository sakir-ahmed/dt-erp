<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import DataTable from '../../components/ui-kit/DataTable.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import SearchBar from '../../components/ui-kit/SearchBar.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { formatNumber } from '../../lib/format';

const props = defineProps({
    users: { type: Object, required: true },
    search: { type: String, required: true },
    canManageUsers: { type: Boolean, required: true },
});

const page = usePage();
const canEditUser = (user) => props.canManageUsers && user.editable;
const query = ref(props.search);
const searching = ref(false);
const columns = computed(() => {
    const labels = page.props.labels;
    const tableColumns = [
        { key: 'name', label: labels.name },
        { key: 'email', label: labels.email },
        { key: 'roles', label: labels.role },
    ];

    if (props.canManageUsers) {
        tableColumns.push({ key: 'actions', label: labels.edit_staff, align: 'right', hideLabel: true, headerClass: 'w-12' });
    }

    return tableColumns;
});
const modalOpen = ref(false);
const editingId = ref(null);
const form = useForm({ name: '', email: '', password: '' });
const fieldRefs = {};
const formId = useId();

const range = computed(() => {
    const locale = page.props.locale;

    return page.props.labels.showing_users
        .replace(':first', formatNumber(props.users.first, locale))
        .replace(':last', formatNumber(props.users.last, locale))
        .replace(':total', formatNumber(props.users.total, locale));
});

function searchUsers() {
    router.get('/users', query.value.trim() ? { search: query.value.trim() } : {}, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { searching.value = true; },
        onFinish: () => { searching.value = false; },
    });
}

function openUser(user = null) {
    editingId.value = user?.id ?? null;
    form.clearErrors();
    form.name = user?.name ?? '';
    form.email = user?.email ?? '';
    form.password = '';
    modalOpen.value = true;
}

function closeUser() {
    modalOpen.value = false;
    form.reset();
}

function saveUser() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            query.value = '';
            closeUser();
        },
        onError: async (errors) => {
            await nextTick();
            fieldRefs[Object.keys(errors)[0]]?.focus();
        },
    };

    if (editingId.value === null) {
        form.post('/users', options);
    } else {
        form.patch(`/users/${editingId.value}`, options);
    }
}

function roleLabels(user) {
    return user.roles.map((role) => page.props.labels[role] ?? role);
}
</script>

<template>
    <Head :title="page.props.labels.users" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.users">
            <template v-if="canManageUsers" #actions>
                <button type="button" class="ui-button ui-button-primary" @click="openUser()">
                    <AppIcon name="add" />{{ page.props.labels.new_staff }}
                </button>
            </template>
        </PageHeader>

        <div class="rounded-lg border border-line bg-surface">
            <SearchBar v-model="query" :label="page.props.labels.search_users" :clear-label="page.props.labels.clear" :has-filter="Boolean(search)" :busy="searching" @search="searchUsers" />

            <template v-if="users.data.length">
                <DataTable :columns="columns" :rows="users.data" :is-row-clickable="canEditUser">
                    <template #cell(name)="{ value }"><p class="max-w-56 truncate font-medium" :title="value">{{ value }}</p></template>
                    <template #cell(email)="{ value }"><p class="max-w-72 truncate" :title="value">{{ value }}</p></template>
                    <template #cell(roles)="{ row }">
                        <span v-for="role in roleLabels(row)" :key="role" class="me-1 inline-block rounded-md bg-selected px-2 text-xs leading-5 text-brand">{{ role }}</span>
                    </template>
                    <template #cell(actions)="{ row }">
                        <button v-if="canEditUser(row)" data-row-action type="button" class="size-6 rounded-md hover:bg-surface" :aria-label="`${page.props.labels.edit_staff} · ${row.name}`" @click="openUser(row)"><AppIcon name="edit" /></button>
                    </template>
                    <template #card="{ row: user }">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold wrap-anywhere">{{ user.name }}</p>
                                <p class="text-muted wrap-anywhere">{{ user.email }}</p>
                                <span v-for="role in roleLabels(user)" :key="role" class="me-1 inline-block rounded-md bg-selected px-2 text-xs leading-5 text-brand">{{ role }}</span>
                            </div>
                            <button v-if="canEditUser(user)" data-row-action type="button" class="ui-button ui-button-icon shrink-0" :aria-label="`${page.props.labels.edit_staff} · ${user.name}`" @click="openUser(user)"><AppIcon name="edit" /></button>
                        </div>
                    </template>
                </DataTable>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line p-3 text-xs text-muted">
                    <span>{{ range }}</span>
                    <div class="flex gap-2">
                        <Link v-if="users.previous" :href="users.previous" class="ui-button">{{ page.props.labels.previous }}</Link>
                        <Link v-if="users.next" :href="users.next" class="ui-button">{{ page.props.labels.next }}</Link>
                    </div>
                </div>
            </template>
            <EmptyState v-else icon="users" :title="search ? page.props.labels.no_search_results : page.props.labels.users_empty" />
        </div>

        <SlideOver v-if="modalOpen" :busy="form.processing" :title="editingId === null ? page.props.labels.new_staff : page.props.labels.edit_staff" :close-label="page.props.labels.close" @close="closeUser">
            <form :id="formId" class="grid gap-x-4 gap-y-3 sm:grid-cols-2" @submit.prevent="saveUser">
                <FormField v-slot="attributes" :label="page.props.labels.name" :error="form.errors.name" required>
                    <input :ref="(element) => fieldRefs.name = element" v-bind="attributes" v-model="form.name" class="ui-input" autocomplete="off" maxlength="255" autofocus>
                </FormField>
                <FormField v-slot="attributes" :label="page.props.labels.email" :error="form.errors.email" required>
                    <input :ref="(element) => fieldRefs.email = element" v-bind="attributes" v-model="form.email" class="ui-input" type="email" autocomplete="off" maxlength="254">
                </FormField>
                <FormField v-slot="attributes" :label="page.props.labels.password" :error="form.errors.password" :required="editingId === null" class="sm:col-span-2">
                    <input :ref="(element) => fieldRefs.password = element" v-bind="attributes" v-model="form.password" class="ui-input" type="password" autocomplete="new-password" minlength="12" maxlength="72">
                    <p v-if="editingId !== null" class="mt-1 text-xs text-muted">{{ page.props.labels.password_keep }}</p>
                </FormField>
            </form>

            <template #footer>
                <button type="button" class="ui-button" :disabled="form.processing" @click="closeUser">{{ page.props.labels.cancel }}</button>
                <button type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="form.processing">{{ page.props.labels.save }}</button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
