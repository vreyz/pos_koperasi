import './bootstrap';
import '../css/app.css';
import i18n from './i18n';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { showValidationPopup } from './Utils/errorPopup';
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
        // Error isian dari server: bila banyak / kolomnya tidak terlihat, popup daftar kolom yang perlu dicek.
        // Ditunda sebentar supaya pesan error di bawah kolom sudah tampil (dipakai untuk menemukan kolomnya).
        router.on('error', (event) => setTimeout(() => showValidationPopup(event.detail.errors), 150));

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
