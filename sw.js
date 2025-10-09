/* global self, caches, indexedDB */
const CACHE_NAME = 'asistencia-pwa-v2';
const OFFLINE_URL = '/public/offline.html';
const STATIC_ASSETS = [
  '/',
  '/login.php',
  '/public/asistencia.php',
  OFFLINE_URL,
  '/public/manifest.webmanifest',
  '/public/js/pwa.js',
  '/recursos/logo.png',
  '/assets/style.css'
];

const DB_NAME = 'asistencia-offline';
const DB_VERSION = 2;
const STORE_ACCIONES = 'acciones';
const STORE_CREDENCIALES = 'credenciales';
const SYNC_TAG = 'sync-asistencias';

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.map(key => (key !== CACHE_NAME ? caches.delete(key) : null)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;

  if (request.method !== 'GET') {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          if (cached) {
            return cached;
          }
          return caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cacheResponse => {
      const fetchPromise = fetch(request)
        .then(networkResponse => {
          const responseClone = networkResponse.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, responseClone));
          return networkResponse;
        })
        .catch(() => cacheResponse);
      return cacheResponse || fetchPromise;
    })
  );
});

self.addEventListener('sync', event => {
  if (event.tag === SYNC_TAG) {
    event.waitUntil(syncPending());
  }
});

self.addEventListener('message', event => {
  if (!event.data) return;
  if (event.data.type === 'SYNC_NOW') {
    event.waitUntil(syncPending());
  }
});

async function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = event => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(STORE_ACCIONES)) {
        db.createObjectStore(STORE_ACCIONES, { keyPath: 'id', autoIncrement: true });
      }
      if (!db.objectStoreNames.contains(STORE_CREDENCIALES)) {
        db.createObjectStore(STORE_CREDENCIALES, { keyPath: 'username' });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function readAllPending() {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_ACCIONES, 'readonly');
    const store = tx.objectStore(STORE_ACCIONES);
    const request = store.getAll();
    request.onsuccess = () => {
      resolve(request.result || []);
      db.close();
    };
    request.onerror = () => {
      reject(request.error);
      db.close();
    };
  });
}

async function deletePending(id) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_ACCIONES, 'readwrite');
    const store = tx.objectStore(STORE_ACCIONES);
    const request = store.delete(id);
    request.onsuccess = () => {
      resolve();
      db.close();
    };
    request.onerror = () => {
      reject(request.error);
      db.close();
    };
  });
}

async function notifyClients(message) {
  const clients = await self.clients.matchAll({ includeUncontrolled: true });
  for (const client of clients) {
    client.postMessage(message);
  }
}

async function syncPending() {
  try {
    const items = await readAllPending();
    if (!items.length) {
      await notifyClients({ type: 'SYNC_EMPTY' });
      return;
    }

    for (const item of items) {
      const formData = new FormData();
      if (item.fields) {
        Object.entries(item.fields).forEach(([key, value]) => {
          formData.append(key, value);
        });
      }
      if (item.foto && item.foto.buffer) {
        const blob = new Blob([item.foto.buffer], { type: item.foto.type || 'image/jpeg' });
        formData.append('foto', blob, item.foto.name || `foto-${item.id}.jpg`);
      }
      const response = await fetch(item.url, {
        method: item.method || 'POST',
        body: formData,
        credentials: 'include'
      });

      if (!response.ok) {
        throw new Error(`Error sincronizando registro pendiente ${item.id}`);
      }

      await deletePending(item.id);
      await notifyClients({ type: 'SYNC_SUCCESS', id: item.id });
    }
  } catch (error) {
    await notifyClients({ type: 'SYNC_ERROR', message: error.message });
    throw error;
  }
}
