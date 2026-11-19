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
import { formatMoneyAmount, formatStock } from '../../lib/format';

const props = defineProps({
    products: { type: Object, required: true },
    search: { type: String, required: true },
    canManageProducts: Boolean,
});

const page = usePage();
const columns = computed(() => {
    const labels = page.props.labels;
    const tableColumns = [
        { key: 'sku', label: labels.sku, cellClass: 'font-medium whitespace-nowrap' },
        { key: 'name', label: labels.name },
        { key: 'unit', label: labels.unit },
        { key: 'sale_price', label: labels.sale_price, align: 'right' },
        { key: 'stock_milliunits', label: labels.stock_on_hand, align: 'right' },
        { key: 'active', label: labels.status },
    ];

    if (props.canManageProducts) {
        tableColumns.push({ key: 'actions', label: labels.edit_product, align: 'right', hideLabel: true, headerClass: 'w-12' });
    }

    return tableColumns;
});
const query = ref(props.search);
const searching = ref(false);
const open = ref(false);
const editing = ref(null);
const form = useForm({ sku: '', name: '', unit: '', sale_price: '', active: true });
const fields = [
    { key: 'sku', max: 64 },
    { key: 'name', max: 255 },
    { key: 'unit', max: 32 },
    { key: 'sale_price', max: 13, inputmode: 'decimal' },
];
const fieldRefs = {};
const formId = useId();

function filter() {
    const search = query.value.trim();
    router.get('/products', search ? { search } : {}, {
        preserveScroll: true,
        onStart: () => { searching.value = true; },
        onFinish: () => { searching.value = false; },
    });
}

function edit(product = null) {
    editing.value = product?.id ?? null;
    form.clearErrors();
    form.reset();

    if (product) {
        Object.keys(form.data()).forEach((key) => {
            form[key] = product[key];
        });
    }

    open.value = true;
}

function save() {
    form.submit(editing.value ? 'patch' : 'post', editing.value ? `/products/${editing.value}` : '/products', {
        onError: async (errors) => {
            await nextTick();
            fieldRefs[Object.keys(errors)[0]]?.focus();
        },
        onSuccess: () => {
            open.value = false;
            query.value = '';
        },
    });
}
</script>

<template>
    <Head :title="page.props.labels.products" />
    <AdminLayout>
        <PageHeader :title="page.props.labels.products">
            <template v-if="canManageProducts" #actions>
                <button type="button" class="ui-button ui-button-primary" @click="edit()">
                    <AppIcon name="add" />{{ page.props.labels.new_product }}
                </button>
            </template>
        </PageHeader>

        <div class="rounded-lg border border-line bg-surface">
            <SearchBar v-model="query" :label="page.props.labels.search_products" :clear-label="page.props.labels.clear" :has-filter="Boolean(search)" :busy="searching" @search="filter" />

            <template v-if="products.data.length">
                <DataTable :columns="columns" :rows="products.data">
                    <template #cell(name)="{ value }"><p class="max-w-56 truncate" :title="value">{{ value }}</p></template>
                    <template #cell(sale_price)="{ value }">{{ formatMoneyAmount(value, page.props.locale) }}</template>
                    <template #cell(stock_milliunits)="{ row, value }">
                        <Link :href="`/products/${row.id}/stock`" class="font-medium text-brand hover:underline" :aria-label="`${page.props.labels.stock_movements} · ${row.sku}`">
                            {{ formatStock(value, page.props.locale) }}
                        </Link>
                    </template>
                    <template #cell(active)="{ value }">
                        <span class="rounded-md px-2 py-0.5 text-xs" :class="value ? 'bg-selected text-brand' : 'bg-page text-muted'">{{ page.props.labels[value ? 'active' : 'inactive'] }}</span>
                    </template>
                    <template #cell(actions)="{ row }">
                        <button type="button" class="size-6 rounded-md hover:bg-surface" :aria-label="`${page.props.labels.edit_product} · ${row.sku}`" @click="edit(row)"><AppIcon name="edit" /></button>
                    </template>
                    <template #card="{ row: product }">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0 wrap-anywhere">
                                <p class="font-semibold">{{ product.name }}</p>
                                <p class="text-muted">{{ product.sku }} · {{ product.unit }}</p>
                                <Link :href="`/products/${product.id}/stock`" class="font-medium text-brand hover:underline" :aria-label="`${page.props.labels.stock_movements} · ${product.sku}`">
                                    {{ page.props.labels.stock_on_hand }} · {{ formatStock(product.stock_milliunits, page.props.locale) }}
                                </Link>
                                <p>{{ page.props.labels[product.active ? 'active' : 'inactive'] }} · ৳{{ formatMoneyAmount(product.sale_price, page.props.locale) }}</p>
                            </div>
                            <button v-if="canManageProducts" type="button" class="ui-button ui-button-icon shrink-0" :aria-label="`${page.props.labels.edit_product} · ${product.sku}`" @click="edit(product)"><AppIcon name="edit" /></button>
                        </div>
                    </template>
                </DataTable>

                <div v-if="products.previous || products.next" class="flex justify-end gap-2 border-t border-line p-3">
                    <Link v-if="products.previous" :href="products.previous" class="ui-button">{{ page.props.labels.previous }}</Link>
                    <Link v-if="products.next" :href="products.next" class="ui-button">{{ page.props.labels.next }}</Link>
                </div>
            </template>
            <EmptyState v-else icon="cubes" :title="page.props.labels.no_products" />
        </div>

        <SlideOver v-if="open" :busy="form.processing" :title="page.props.labels[editing ? 'edit_product' : 'new_product']" :close-label="page.props.labels.close" @close="open = false">
            <form :id="formId" class="grid gap-x-4 gap-y-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField v-for="field in fields" :key="field.key" v-slot="attributes" :label="page.props.labels[field.key]" :error="form.errors[field.key]" required>
                    <input :ref="(element) => fieldRefs[field.key] = element" v-bind="attributes" v-model="form[field.key]" class="ui-input" :class="{ 'text-right tabular-nums': field.inputmode === 'decimal' }" :maxlength="field.max" :inputmode="field.inputmode" :autofocus="field.key === 'sku'">
                </FormField>
                <FormField v-slot="attributes" :label="page.props.labels.status" :error="form.errors.active">
                    <select :ref="(element) => fieldRefs.active = element" v-bind="attributes" v-model="form.active" class="ui-input">
                        <option :value="true">{{ page.props.labels.active }}</option>
                        <option :value="false">{{ page.props.labels.inactive }}</option>
                    </select>
                </FormField>
            </form>

            <template #footer>
                <button type="button" class="ui-button" :disabled="form.processing" @click="open = false">{{ page.props.labels.cancel }}</button>
                <button type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="form.processing">{{ page.props.labels.save }}</button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
