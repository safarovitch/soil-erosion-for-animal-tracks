import "./bootstrap";
import "ol/ol.css";

import { createApp, h } from "vue";
import { createInertiaApp } from "@inertiajs/vue3";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createPinia } from "pinia";
import { ZiggyVue } from "ziggy-js";

createInertiaApp({
    title: (title) =>
        title ? `${title} - Soil Erosion Watch` : "Soil Erosion Watch",
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob("./Pages/**/*.vue")
        ),
    setup({ el, App, props, plugin }) {
        return createApp({
            render: () => h(App, props),
        })
            .use(plugin)
            .use(createPinia())
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: "#4B5563",
    },
});
