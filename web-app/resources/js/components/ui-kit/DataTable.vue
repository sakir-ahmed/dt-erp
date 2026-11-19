<script setup>
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
});

const alignment = {
    right: 'text-right tabular-nums',
    center: 'text-center',
};
</script>

<template>
    <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-[13px]">
            <thead class="border-b border-line bg-page/60 text-xs text-muted">
                <tr>
                    <th v-for="column in columns" :key="column.key" scope="col" class="h-8 whitespace-nowrap px-3" :class="[alignment[column.align] ?? 'text-start', column.headerClass]">
                        <span :class="{ 'sr-only': column.hideLabel }">{{ column.label }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="row in rows" :key="row.id" class="hover:bg-selected">
                    <td v-for="column in columns" :key="column.key" class="h-8 px-3" :class="[alignment[column.align] ?? 'text-start', column.cellClass]">
                        <slot :name="`cell(${column.key})`" :row="row" :value="row[column.key]">{{ row[column.key] }}</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <ul class="divide-y divide-line md:hidden">
        <li v-for="row in rows" :key="row.id" class="p-3 text-[13px]">
            <slot name="card" :row="row" />
        </li>
    </ul>
</template>
