/* Card Ledger PWA worker. API responses and private records are deliberately never cached. */
const APP_BASE = new URL('./', self.registration.scope).pathname;
const CACHE_NAME = 'card-ledger-shell-v2';
const SHELL = [APP_BASE, `${APP_BASE}manifest.webmanifest`, `${APP_BASE}icons/icon-192.png`, `${APP_BASE}icons/icon-512.png`];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('card-ledger-') && key !== CACHE_NAME).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith(APP_BASE) || url.pathname.startsWith(`${APP_BASE}api/`)) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then((response) => {
      if (response.ok) caches.open(CACHE_NAME).then((cache) => cache.put(APP_BASE, response.clone()));
      return response;
    }).catch(async () => (await caches.match(APP_BASE)) || Response.error()));
    return;
  }
  if (/\.(?:js|css|png|svg|webmanifest|woff2?)$/i.test(url.pathname)) {
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok) caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone()));
      return response;
    })));
  }
});
