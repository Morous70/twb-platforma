// Service worker TWB Plánovač médií – umožní instalaci jako aplikace na plochu.
// Nic z aplikace neukládá do mezipaměti (data musí být vždy aktuální); jen při výpadku sítě ukáže stránku „Jste offline“.
const CACHE = 'twb-planovac-offline-2';
const OFFLINE = ['offline.html'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(OFFLINE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return; // vše ostatní jde normálně na server
  e.respondWith(fetch(e.request).catch(() => caches.match('offline.html')));
});
