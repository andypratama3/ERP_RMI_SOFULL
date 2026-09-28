/**
 * ERP RMI SOFULL — Service Worker
 * Versi: erp_pwa_v1
 * Spec: docs/governance/21_PWA_MOBILE_UX_SPEC.md
 *
 * Fetch rules:
 * - /api/* → Network only (never cache)
 * - mode === navigate → Network first, fallback offline.html
 * - script/style/image/font → Cache first
 * - Other → Network only
 */
const CACHE_NAME = 'erp_pwa_v1';
// offline.html same dir as sw.js (public/)
const OFFLINE_PATH = 'offline.html';

self.addEventListener('install', (event) => {
  const base = new URL('./', self.location.href).href;
  const offlineUrl = new URL(OFFLINE_PATH, base).href;
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll([offlineUrl]))
      .then(() => self.skipWaiting())
      .catch(() => {})
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const path = url.pathname;

  // API: never cache
  if (path.indexOf('/api/') === 0) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Navigation: network first, fallback offline
  if (event.request.mode === 'navigate') {
    const offlineUrl = new URL(OFFLINE_PATH, self.location.href).href;
    event.respondWith(
      fetch(event.request).catch(() => caches.match(offlineUrl))
    );
    return;
  }

  // Static assets: cache first
  const accept = event.request.headers.get('Accept') || '';
  if (
    path.match(/\.(js|css|woff2?|ttf|eot|svg|png|jpg|jpeg|gif|webp|ico)$/i) ||
    accept.includes('text/css') ||
    accept.includes('application/javascript')
  ) {
    event.respondWith(
      caches.match(event.request).then((cached) => {
        return cached || fetch(event.request).then((res) => {
          const clone = res.clone();
          if (res.ok && res.type === 'basic') {
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return res;
        });
      })
    );
    return;
  }

  // Default: network only
  event.respondWith(fetch(event.request));
});
