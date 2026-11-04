/* sw.js — service worker (must stay in the site root).
 * - caches static assets so pages still look right when the server is down
 * - serves the last copy of the application-form pages when the server can't be reached
 * - shows offline.html for anything else
 * Requires HTTPS in production (localhost is fine for development).
 */
const VERSION = 'v1';
const STATIC  = 'static-' + VERSION;
const PAGES   = 'pages-' + VERSION;
const OFFLINE = 'offline.html';

const PAGE_ALLOW = ['application_type.php', 'business_info.php', 'business_address.php', 'owner_info.php',
                    'additional_info.php', 'documents.php', 'review_submit.php', 'dashboard.php', 'new_permit.php'];
const STATIC_HOSTS = ['cdn.jsdelivr.net', 'fonts.googleapis.com', 'fonts.gstatic.com',
                      'cdnjs.cloudflare.com', 'code.jquery.com'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(STATIC).then(c => c.addAll([OFFLINE])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => ![STATIC, PAGES].includes(k)).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

function isStatic(url) {
    if (STATIC_HOSTS.includes(url.hostname)) return true;
    return url.origin === location.origin && /\.(css|js|png|jpe?g|gif|svg|ico|woff2?|ttf)$/i.test(url.pathname);
}

async function serverDown(res) {
    if (res.status >= 500) return true;
    // connect.php die()s with a short 200 page when the database is down
    const type = res.headers.get('content-type') || '';
    if (type.includes('text/html')) {
        const t = await res.clone().text();
        if (t.length < 400 && t.includes('Connection failed')) return true;
    }
    return false;
}

async function networkFirstPage(req) {
    const name = new URL(req.url).pathname.split('/').pop();
    try {
        const res = await fetch(req);
        if (await serverDown(res)) throw new Error('server down');
        if (res.ok && !res.redirected && PAGE_ALLOW.includes(name)) {
            const c = await caches.open(PAGES);
            c.put(req.url, res.clone());
        }
        return res;
    } catch (err) {
        const cached = await caches.match(req.url, { cacheName: PAGES });
        if (cached) return cached;
        const off = await caches.match(OFFLINE);
        return off || new Response('Server unavailable', { status: 503 });
    }
}

async function staleWhileRevalidate(req) {
    const cache  = await caches.open(STATIC);
    const cached = await cache.match(req);
    const fresh  = fetch(req).then(res => { if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone()); return res; })
                             .catch(() => cached);
    return cached || fresh;
}

self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;                       // POSTs go straight to the network (queued by offline-sync.js)
    const url = new URL(req.url);
    if (url.pathname.endsWith('/ping.php')) return;         // health check must never be cached

    if (url.pathname.endsWith('/logout.php')) {             // don't keep personal pages after logout
        e.waitUntil(caches.delete(PAGES));
        return;
    }
    if (req.mode === 'navigate') { e.respondWith(networkFirstPage(req)); return; }
    if (isStatic(url))           { e.respondWith(staleWhileRevalidate(req)); }
});
