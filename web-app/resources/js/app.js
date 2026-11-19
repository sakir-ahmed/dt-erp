import '@fontsource/inter/400.css';
import '@fontsource/inter/600.css';
import '@fontsource/noto-sans-bengali/400.css';
import '@fontsource/noto-sans-bengali/600.css';
import '@fontsource/poppins/600.css';
import '../css/app.css';
import 'vue-sonner/style.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, nextTick } from 'vue';
import { toast } from 'vue-sonner';
import ToastHost from './components/ui-kit/ToastHost.vue';

function showSuccess(flash) {
    if (flash?.success) {
        toast.success(flash.success);
    }
}

router.on('flash', ({ detail }) => showSuccess(detail.flash));

router.on('navigate', ({ detail }) => {
    document.documentElement.lang = detail.page.props.locale;
});

createInertiaApp({
    title: (title) => title ? `${title} · DT ERP` : 'DT ERP',
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => [h(App, props), h(ToastHost)] }).use(plugin).mount(el);
        nextTick(() => showSuccess(props.initialPage.flash));
    },
});
