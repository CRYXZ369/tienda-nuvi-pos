/* La caja de emergencia es estática; nunca se guardan páginas autenticadas. */
const CACHE = 'nuvi-pwa-shell-v20';
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;
const SHELL = [
    OFFLINE_URL,
    new URL('js/nuvi-emergencia.js', self.registration.scope).href,
    new URL('js/caja-emergencia.js', self.registration.scope).href,
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

    // Las páginas autenticadas siempre se piden al servidor y nunca se guardan.
    if (request.mode === 'navigate') {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        event.respondWith(fetch(request, { signal: controller.signal })
            .then(async response => response.status >= 500 ? (await caches.match(OFFLINE_URL)) || response : response)
            .catch(() => caches.match(OFFLINE_URL))
            .finally(() => clearTimeout(timeout)));
        return;
    }
    const url = new URL(request.url);
    if (url.origin === self.location.origin && SHELL.includes(url.href)) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
    }
});
