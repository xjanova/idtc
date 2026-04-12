const CACHE_NAME = 'idtc-cache-v1';

const APP_SHELL = [
  '/trade.html',
  '/dashboard.html',
  '/sale.html',
  '/index.html',
  '/logotoken.png',
  '/manifest.json'
];

const IFRAME_HOSTS = ['dexscreener.com', 'geckoterminal.com'];

// ---------- Install: pre-cache app shell ----------
self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
});

// ---------- Activate: claim clients & purge old caches ----------
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

// ---------- Fetch handler ----------
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache iframe embeds
  if (IFRAME_HOSTS.some((host) => url.hostname.includes(host))) {
    return; // let the browser handle it (Network Only)
  }

  // --- Same-origin local assets: Cache First ---
  if (url.origin === self.location.origin) {
    event.respondWith(cacheFirst(event.request));
    return;
  }

  // --- CDN assets: Stale While Revalidate ---
  if (
    url.hostname.includes('jsdelivr.net') ||
    url.hostname.includes('googleapis.com') ||
    url.hostname.includes('gstatic.com')
  ) {
    event.respondWith(staleWhileRevalidate(event.request));
    return;
  }

  // --- API / RPC requests: Network First (5 s timeout) ---
  if (
    url.hostname.includes('etherscan') ||
    url.hostname.includes('publicnode')
  ) {
    event.respondWith(networkFirst(event.request, 5000));
    return;
  }

  // --- Everything else: Network Only ---
  // (no respondWith — browser default)
});

// ===================== Strategies =====================

function cacheFirst(request) {
  return caches.match(request).then((cached) => {
    if (cached) return cached;
    return fetch(request).then((response) => {
      if (response && response.ok) {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
      }
      return response;
    });
  });
}

function staleWhileRevalidate(request) {
  return caches.open(CACHE_NAME).then((cache) =>
    cache.match(request).then((cached) => {
      const networkFetch = fetch(request).then((response) => {
        if (response && response.ok) {
          cache.put(request, response.clone());
        }
        return response;
      });
      return cached || networkFetch;
    })
  );
}

function networkFirst(request, timeoutMs) {
  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      caches.match(request).then((cached) => {
        if (cached) resolve(cached);
      });
    }, timeoutMs);

    fetch(request)
      .then((response) => {
        clearTimeout(timer);
        if (response && response.ok) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        resolve(response);
      })
      .catch(() => {
        clearTimeout(timer);
        caches.match(request).then((cached) => {
          resolve(cached || new Response('Network error', { status: 503 }));
        });
      });
  });
}
