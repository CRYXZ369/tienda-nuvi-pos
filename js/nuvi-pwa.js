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

        const aviso = document.createElement('div');
        aviso.setAttribute('role', 'status');
        aviso.hidden = true;
        aviso.style.cssText = 'position:fixed;bottom:1rem;left:1rem;right:1rem;z-index:2147483647;padding:1rem;background:#fff3cd;color:#3d2c00;border:2px solid #9a6b00;border-radius:.75rem;font:600 16px sans-serif;box-shadow:0 2px 10px #0005';
        const texto = document.createElement('span');
        const enlace = document.createElement('a');
        enlace.href = new URL('offline.html', scopeUrl).href;
        enlace.target = '_blank';
        enlace.rel = 'noopener';
        enlace.textContent = 'Abrir caja de emergencia';
        enlace.style.cssText = 'display:inline-block;margin:.5rem;padding:.7rem;background:#684400;color:white;border-radius:.5rem';
        aviso.append(texto, enlace);
        document.body.append(aviso);

        let comprobando = false;
        async function comprobarServidor() {
            if (comprobando) return;
            comprobando = true;
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 5000);
            try {
                const respuesta = await fetch(new URL('estado_conexion.php', scopeUrl).href, {
                    cache: 'no-store', credentials: 'same-origin', signal: controller.signal
                });
                if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`);
                aviso.hidden = true;
            } catch (error) {
                let preparada = false;
                try {
                    preparada = Boolean(navigator.serviceWorker.controller
                        && await caches.match(new URL('offline.html', scopeUrl).href)
                        && await caches.match(new URL('js/caja-emergencia.js', scopeUrl).href)
                        && await caches.match(new URL('js/nuvi-emergencia.js', scopeUrl).href));
                } catch (cacheError) {
                    console.warn('No se pudo comprobar la caja de emergencia.', cacheError);
                }
                texto.textContent = preparada
                    ? 'Sin conexión con el servidor. Conserva esta pantalla si tienes cambios sin guardar. '
                    : 'Sin conexión con el servidor. La caja de emergencia no está preparada en este dispositivo.';
                enlace.hidden = !preparada;
                aviso.hidden = false;
            } finally {
                clearTimeout(timer);
                comprobando = false;
            }
        }
        window.addEventListener('offline', comprobarServidor);
        window.addEventListener('online', comprobarServidor);
        setInterval(comprobarServidor, 15000);
        comprobarServidor();
    });
})();
