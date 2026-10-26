/* MixCreate service worker: push notifications and an offline page. */
const CACHE = 'mixcreate-v1';
const OFFLINE = new URL('offline.html', self.registration.scope).toString();

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    if (e.request.mode === 'navigate') {
        e.respondWith(fetch(e.request).catch(() => caches.match(OFFLINE)));
    }
});

self.addEventListener('push', (e) => {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'MixCreate', body: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(data.title || 'MixCreate', {
        body: data.body || '',
        icon: new URL('assets/icon-192.png', self.registration.scope).toString(),
        badge: new URL('assets/icon-192.png', self.registration.scope).toString(),
        tag: data.tag,
        data: { url: data.url || self.registration.scope },
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = e.notification.data && e.notification.data.url;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) {
            if (c.url === url && 'focus' in c) return c.focus();
        }
        return self.clients.openWindow(url);
    }));
});
