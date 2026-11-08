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
        const cerrar = document.createElement('button');
        cerrar.type = 'button';
        cerrar.textContent = 'Seguir en esta pantalla';
        cerrar.style.cssText = 'margin:.5rem;padding:.7rem;background:white;color:#3d2c00;border:1px solid #684400;border-radius:.5rem;font:inherit;cursor:pointer';
        // El tema compartido reemplaza fondos blancos con !important.
        cerrar.style.setProperty('background', '#ffffff', 'important');
        cerrar.style.setProperty('color', '#3d2c00', 'important');
        let descartado = false;
        cerrar.addEventListener('click', () => { descartado = true; aviso.hidden = true; });
        aviso.append(texto, enlace, cerrar);
        document.body.append(aviso);

        let comprobando = false;
        let fallosConsecutivos = 0;
        let timerReintento = null;
        let revisionVisibilidad = 0;
        let controladorActivo = null;
        async function comprobarServidor() {
            if (comprobando || document.hidden) return;
            comprobando = true;
            const controller = new AbortController();
            controladorActivo = controller;
            const revision = revisionVisibilidad;
            const timer = setTimeout(() => controller.abort(), 5000);
            try {
                const respuesta = await fetch(new URL('estado_conexion.php', scopeUrl).href, {
                    cache: 'no-store', credentials: 'same-origin', signal: controller.signal
                });
                if (revision !== revisionVisibilidad) return;
                if (!respuesta.ok) throw new Error(`HTTP ${respuesta.status}`);
                clearTimeout(timerReintento);
                fallosConsecutivos = 0;
                aviso.hidden = true;
                descartado = false;
            } catch (error) {
                if (document.hidden || revision !== revisionVisibilidad) {
                    fallosConsecutivos = 0;
                    return;
                }
                fallosConsecutivos++;
                if (fallosConsecutivos < 2) {
                    clearTimeout(timerReintento);
                    timerReintento = setTimeout(comprobarServidor, 3000);
                    return;
                }
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
                aviso.hidden = descartado;
            } finally {
                clearTimeout(timer);
                controladorActivo = null;
                comprobando = false;
                if (revision !== revisionVisibilidad && !document.hidden) comprobarServidor();
            }
        }
        window.addEventListener('offline', comprobarServidor);
        window.addEventListener('online', comprobarServidor);
        document.addEventListener('visibilitychange', () => {
            revisionVisibilidad++;
            if (document.hidden) {
                if (controladorActivo) controladorActivo.abort();
                clearTimeout(timerReintento);
                fallosConsecutivos = 0;
                aviso.hidden = true;
            } else {
                comprobarServidor();
            }
        });
        setInterval(comprobarServidor, 15000);
        comprobarServidor();
    });
})();
