<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId } from 'vue';
import AppIcon from '../../components/AppIcon.vue';
import DataTable from '../../components/ui-kit/DataTable.vue';
import EmptyState from '../../components/ui-kit/EmptyState.vue';
import FormField from '../../components/ui-kit/FormField.vue';
import PageHeader from '../../components/ui-kit/PageHeader.vue';
import SlideOver from '../../components/ui-kit/SlideOver.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { formatDhakaDateTime, formatStock } from '../../lib/format';

const props = defineProps({
    product: { type: Object, required: true },
    movements: { type: Object, required: true },
    canManageStock: Boolean,
});

const page = usePage();
const columns = computed(() => [
    { key: 'recordedAt', label: page.props.labels.recorded_at, cellClass: 'whitespace-nowrap' },
    { key: 'reason', label: page.props.labels.reason },
    { key: 'delta', label: page.props.labels.quantity, align: 'right' },
    { key: 'balance', label: page.props.labels.balance, align: 'right' },
]);
const open = ref(false);
const fieldRefs = {};
const formId = useId();
const form = useForm({ direction: 'in', quantity: '', reason: '', operation_id: '' });

function openForm() {
    form.reset();
    form.clearErrors();
    form.direction = props.product.active ? 'in' : 'out';
    form.operation_id = crypto.randomUUID();
    open.value = true;
}

function save() {
    form.post(`/products/${props.product.id}/stock`, {
        onSuccess: () => { open.value = false; },
        onError: async (errors) => {
            await nextTick();
            fieldRefs[Object.keys(errors)[0]]?.focus();
        },
    });
}
</script>

<template>
    <Head :title="page.props.labels.stock_movements" />
    <AdminLayout>
        <Link href="/products" class="mb-2 inline-block text-xs font-medium text-brand hover:underline">{{ page.props.labels.back_to_products }}</Link>
        <PageHeader :title="page.props.labels.stock_movements">
            <template v-if="canManageStock" #actions>
                <button type="button" class="ui-button ui-button-primary" @click="openForm">
                    <AppIcon name="add" />{{ page.props.labels.record_movement }}
                </button>
            </template>
        </PageHeader>

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface p-3">
            <div>
                <h2 class="text-sm font-semibold">{{ product.name }}</h2>
                <p class="text-xs text-muted">{{ product.sku }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-muted">{{ page.props.labels.stock_on_hand }}</p>
                <p class="text-lg font-semibold tabular-nums">{{ formatStock(product.stock_milliunits, page.props.locale) }} {{ product.unit }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-line bg-surface">
            <template v-if="movements.data.length">
                <DataTable :columns="columns" :rows="movements.data">
                    <template #cell(recordedAt)="{ value }">{{ formatDhakaDateTime(value, page.props.locale) }}</template>
                    <template #cell(reason)="{ value }"><p class="max-w-60 truncate" :title="value">{{ value }}</p></template>
                    <template #cell(delta)="{ value }">
                        <span :class="value < 0 ? 'text-danger' : 'text-brand'">{{ formatStock(value, page.props.locale, true) }}</span>
                    </template>
                    <template #cell(balance)="{ value }">{{ formatStock(value, page.props.locale) }}</template>
                    <template #card="{ row: movement }">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0 wrap-anywhere">
                                <p class="font-medium">{{ movement.reason }}</p>
                                <p class="text-muted">{{ formatDhakaDateTime(movement.recordedAt, page.props.locale) }}</p>
                            </div>
                            <div class="shrink-0 text-right tabular-nums">
                                <p :class="movement.delta < 0 ? 'text-danger' : 'text-brand'">{{ formatStock(movement.delta, page.props.locale, true) }}</p>
                                <p class="text-xs text-muted">{{ page.props.labels.balance }} · {{ formatStock(movement.balance, page.props.locale) }}</p>
                            </div>
                        </div>
                    </template>
                </DataTable>

                <div v-if="movements.previous || movements.next" class="flex justify-end gap-2 border-t border-line p-3">
                    <Link v-if="movements.previous" :href="movements.previous" class="ui-button">{{ page.props.labels.previous }}</Link>
                    <Link v-if="movements.next" :href="movements.next" class="ui-button">{{ page.props.labels.next }}</Link>
                </div>
            </template>
            <EmptyState v-else icon="cubes" :title="page.props.labels.no_movements" />
        </div>

        <SlideOver v-if="open" :title="page.props.labels.record_movement" :close-label="page.props.labels.close" :busy="form.processing" @close="open = false">
            <form :id="formId" class="grid gap-x-4 gap-y-3 sm:grid-cols-2" @submit.prevent="save">
                <FormField v-slot="attributes" :label="page.props.labels.direction" :error="form.errors.direction" required>
                    <select :ref="(element) => fieldRefs.direction = element" v-bind="attributes" v-model="form.direction" class="ui-input">
                        <option value="in" :disabled="!product.active">{{ page.props.labels.stock_in }}</option>
                        <option value="out">{{ page.props.labels.stock_out }}</option>
                    </select>
                </FormField>
                <FormField v-slot="attributes" :label="`${page.props.labels.quantity} (${product.unit})`" :error="form.errors.quantity" required>
                    <input :ref="(element) => fieldRefs.quantity = element" v-bind="attributes" v-model="form.quantity" class="ui-input text-right tabular-nums" maxlength="13" inputmode="decimal" autofocus>
                </FormField>
                <FormField v-slot="attributes" class="sm:col-span-2" :label="page.props.labels.reason" :error="form.errors.reason" required>
                    <textarea :ref="(element) => fieldRefs.reason = element" v-bind="attributes" v-model="form.reason" maxlength="255" rows="3" class="w-full resize-y rounded-md border border-line bg-surface px-2 py-1.5 text-[13px] aria-invalid:border-danger" />
                </FormField>
                <p v-if="form.errors.operation_id" role="alert" class="text-xs text-danger sm:col-span-2">{{ form.errors.operation_id }}</p>
            </form>

            <template #footer>
                <button type="button" class="ui-button" :disabled="form.processing" @click="open = false">{{ page.props.labels.cancel }}</button>
                <button type="submit" :form="formId" class="ui-button ui-button-primary" :disabled="form.processing">{{ page.props.labels.save }}</button>
            </template>
        </SlideOver>
    </AdminLayout>
</template>
