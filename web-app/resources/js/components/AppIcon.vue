<script setup>
import { faCubes, faMoon, faTableColumns, faUsers, faTriangleExclamation, faBars, faPlus, faXmark, faMagnifyingGlass, faPen } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';

defineProps({
    name: { type: String, required: true },
});

const icons = {
    cubes: faCubes,
    dashboard: faTableColumns,
    moon: faMoon,
    users: faUsers,
    menu: faBars,
    add: faPlus,
    close: faXmark,
    search: faMagnifyingGlass,
    edit: faPen,
    warning: faTriangleExclamation,
};
</script>

<template>
    <FontAwesomeIcon :icon="icons[name]" aria-hidden="true" />
</template>
