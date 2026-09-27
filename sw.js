/* Miscelánea Nuvi PWA.
 * No guarda pantallas, ventas ni respuestas de API: la caja siempre necesita
 * conexión al servidor para evitar ventas duplicadas o datos desactualizados.
 */
const CACHE = 'nuvi-pwa-shell-v1';
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;
const SHELL = [
    OFFLINE_URL,
    new URL('assets/nuvi-pwa-192.png', self.registration.scope).href,
    new URL('assets/nuvi-pwa-512.png', self.registration.scope).href
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names
                .filter((name) => name.startsWith('nuvi-pwa-') && name !== CACHE)
                .map((name) => caches.delete(name))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || request.url.startsWith('chrome-extension:')) return;

    // Las páginas PHP se piden siempre al servidor; si no hay red sólo se
    // muestra el aviso. Nunca se sirven páginas autenticadas desde caché.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
});
