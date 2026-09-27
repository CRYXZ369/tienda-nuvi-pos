(() => {
    'use strict';

    let installEvent = null;
    const manifest = document.querySelector('link[rel="manifest"]');
    const installButton = document.getElementById('nuvi-install-app');

    const setInstallVisible = (visible) => {
        if (!installButton) return;
        installButton.hidden = !visible;
        installButton.classList.toggle('available', visible);
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent = event;
        setInstallVisible(true);
    });

    window.addEventListener('appinstalled', () => {
        installEvent = null;
        setInstallVisible(false);
    });

    if (installButton) {
        installButton.addEventListener('click', async () => {
            if (!installEvent) return;
            installButton.disabled = true;
            try {
                installEvent.prompt();
                await installEvent.userChoice;
            } catch (error) {
                console.warn('No se pudo mostrar la instalación de Nuvi.', error);
            } finally {
                installEvent = null;
                setInstallVisible(false);
                installButton.disabled = false;
            }
        });
    }

    if (!manifest || !('serviceWorker' in navigator) || !window.isSecureContext) return;
    const scopeUrl = new URL('./', manifest.href);
    const workerUrl = new URL('sw.js', scopeUrl);
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(workerUrl.href, { scope: scopeUrl.pathname })
            .catch((error) => console.warn('No se pudo preparar Nuvi sin conexión.', error));
    });
})();
