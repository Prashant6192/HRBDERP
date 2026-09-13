// Keeps the floor shell installable. Pages themselves always come from the
// server: stock figures must never be served stale from a cache.
const CACHE = 'hrbd-floor-shell-v1';
const SHELL = [
    '/manifest.webmanifest',
    '/favicon.svg',
    '/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => k !== CACHE)
                        .map((k) => caches.delete(k)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || !SHELL.includes(url.pathname)) {
        return;
    }
    event.respondWith(
        caches.match(event.request).then((hit) => hit ?? fetch(event.request)),
    );
});
