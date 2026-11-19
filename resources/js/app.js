import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} · Beacon Support` : 'Beacon Support'),

    // Eager glob rather than a dynamic import: this app has a handful of pages,
    // and code-splitting them costs a round trip per navigation to save a few
    // kilobytes that were already downloaded.
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: { color: '#2563eb' },
});
