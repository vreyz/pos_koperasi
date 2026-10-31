import './bootstrap';
import '../css/app.css';
import i18n from './i18n';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeSwitcherProvider } from './Context/ThemeSwitcherContext';
import { OnlineStatusProvider } from './Context/OnlineStatusContext';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        // Bahasa tampilan mengikuti bahasa akun/sesi di server, bukan sisa pilihan lama di browser.
        const syncLocale = (locale) => locale && i18n.language !== locale && i18n.changeLanguage(locale);
        syncLocale(props.initialPage.props.locale?.current);
        router.on('navigate', (event) => syncLocale(event.detail.page.props.locale?.current));

        root.render(
            <ThemeSwitcherProvider>
                <OnlineStatusProvider>
                    <App {...props} />
                </OnlineStatusProvider>
            </ThemeSwitcherProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
