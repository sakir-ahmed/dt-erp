<script setup>
import { faBookOpen, faPlay, faPlug, faBell, faInbox, faChartLine, faPlugCircleXmark, faSun, faWifi, faChevronDown, faChevronLeft, faChevronRight, faEye, faEyeSlash, faCubes, faMoon, faTableColumns, faUsers, faTriangleExclamation, faBars, faPlus, faXmark, faMagnifyingGlass, faPen, faPaperclip, faFile, faFilePdf, faCopy, faPhone, faLink, faGear } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';

defineProps({
    name: { type: String, required: true },
});

const icons = {
    paperclip: faPaperclip,
    bell: faBell,
    plug: faPlug,
    play: faPlay,
    book: faBookOpen,
    inbox: faInbox,
    'chart-line': faChartLine,
    'plug-off': faPlugCircleXmark,
    sun: faSun,
    wifi: faWifi,
    'chevron-down': faChevronDown,
    'chevron-left': faChevronLeft,
    'chevron-right': faChevronRight,
    eye: faEye,
    'eye-slash': faEyeSlash,
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
    file: faFile,
    'file-pdf': faFilePdf,
    copy: faCopy,
    phone: faPhone,
    link: faLink,
    settings: faGear,
};
</script>

<template>
    <FontAwesomeIcon :icon="icons[name]" aria-hidden="true" />
</template>
