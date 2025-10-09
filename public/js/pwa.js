(function () {
  const DB_NAME = 'asistencia-offline';
  const DB_VERSION = 2;
  const STORE_ACCIONES = 'acciones';
  const STORE_CREDENCIALES = 'credenciales';
  const SYNC_TAG = 'sync-asistencias';
  const SW_PATH = '/sw.js';

  let pendingListeners = [];
  let registered = false;

  function log(...args) {
    console.log('[AsistenciaPWA]', ...args);
  }

  function openDB() {
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

  async function addPending(entry) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ACCIONES, 'readwrite');
      const store = tx.objectStore(STORE_ACCIONES);
      const request = store.add(entry);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  async function getAllPending() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ACCIONES, 'readonly');
      const store = tx.objectStore(STORE_ACCIONES);
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  async function deletePending(id) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_ACCIONES, 'readwrite');
      const store = tx.objectStore(STORE_ACCIONES);
      const request = store.delete(id);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  function emitPendingCount(count) {
    pendingListeners.forEach(fn => {
      try {
        fn(count);
      } catch (err) {
        console.error(err);
      }
    });
  }

  async function updatePendingCount() {
    try {
      const items = await getAllPending();
      emitPendingCount(items.length);
    } catch (error) {
      console.error('Error contando pendientes', error);
    }
  }

  async function queueSubmission(form) {
    const formData = new FormData(form);
    const fotoFile = formData.get('foto');

    if (!(fotoFile instanceof File) || !fotoFile.size) {
      throw new Error('La foto es obligatoria para el registro offline.');
    }

    const buffer = await fotoFile.arrayBuffer();
    formData.delete('foto');

    const fields = {};
    formData.forEach((value, key) => {
      fields[key] = value;
    });

    const targetUrl = new URL(form.getAttribute('action') || window.location.href, window.location.href).toString();

    const entry = {
      url: targetUrl,
      method: (form.getAttribute('method') || 'POST').toUpperCase(),
      createdAt: Date.now(),
      fields,
      foto: {
        buffer,
        type: fotoFile.type,
        name: fotoFile.name || `offline-${Date.now()}.jpg`
      }
    };

    const id = await addPending(entry);
    log('Registro guardado offline', { id, tipo: fields.tipo_asistencia, empleado: fields.empleado_nombre });
    await updatePendingCount();

    if ('serviceWorker' in navigator) {
      const registration = await navigator.serviceWorker.ready;
      if ('sync' in registration) {
        try {
          await registration.sync.register(SYNC_TAG);
        } catch (error) {
          console.warn('Background Sync no disponible, sincronizando manualmente.', error);
          await syncPendingRequests();
        }
      } else {
        await syncPendingRequests();
      }
    }

    return id;
  }

  async function syncPendingRequests() {
    try {
      const items = await getAllPending();
      if (!items.length) {
        emitPendingCount(0);
        return { ok: true, synced: 0 };
      }

      let synced = 0;
      for (const item of items) {
        const formData = new FormData();
        if (item.fields) {
          Object.entries(item.fields).forEach(([key, value]) => formData.append(key, value));
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
          throw new Error(`Error sincronizando ${item.id}`);
        }

        await deletePending(item.id);
        synced += 1;
        emitPendingCount(items.length - synced);
      }

      return { ok: true, synced };
    } catch (error) {
      console.error('Error sincronizando manualmente', error);
      return { ok: false, error };
    }
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      console.warn('Service Worker no soportado en este navegador.');
      return;
    }
    if (registered) return;
    registered = true;
    navigator.serviceWorker.register(SW_PATH).then(reg => {
      log('Service Worker registrado', reg.scope);
    }).catch(err => {
      console.error('No se pudo registrar el Service Worker', err);
    });

    navigator.serviceWorker.addEventListener('message', event => {
      if (!event.data) return;
      if (event.data.type === 'SYNC_SUCCESS' || event.data.type === 'SYNC_EMPTY') {
        updatePendingCount();
      }
      if (event.data.type === 'SYNC_ERROR') {
        console.warn('Error en sincronización en segundo plano:', event.data.message);
      }
    });
  }

  function requestSyncFromSW() {
    if (!navigator.serviceWorker.controller) {
      return;
    }
    navigator.serviceWorker.controller.postMessage({ type: 'SYNC_NOW' });
  }

  function init(options = {}) {
    registerServiceWorker();
    updatePendingCount();

    if (options.onPendingChange && typeof options.onPendingChange === 'function') {
      pendingListeners.push(options.onPendingChange);
    }

    if (options.onSyncStatus && typeof options.onSyncStatus === 'function') {
      pendingListeners.push(count => options.onSyncStatus({ pending: count }));
    }

    window.addEventListener('online', () => {
      requestSyncFromSW();
      syncPendingRequests();
    });
  }

  async function hashCredential(username, password) {
    const normalized = `${username}||${password || ''}`;
    try {
      if (typeof crypto !== 'undefined' && crypto.subtle && TextEncoder) {
        const data = new TextEncoder().encode(normalized);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
      }
    } catch (error) {
      console.warn('Fallo hashing con SubtleCrypto, usando base64.', error);
    }
    return btoa(normalized);
  }

  async function saveCredential({ username, password, rol = '', redirect = '/', nombre = '' }) {
    const normalized = (username || '').trim().toLowerCase();
    if (!normalized) {
      throw new Error('Usuario requerido para almacenar credenciales.');
    }
    const hash = await hashCredential(normalized, password || '');
    const record = {
      username: normalized,
      hash,
      rol,
      nombre,
      redirect,
      updatedAt: Date.now()
    };
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_CREDENCIALES, 'readwrite');
      const store = tx.objectStore(STORE_CREDENCIALES);
      const request = store.put(record);
      request.onsuccess = () => resolve(record);
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  async function getCredential(username) {
    const normalized = (username || '').trim().toLowerCase();
    if (!normalized) return null;
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_CREDENCIALES, 'readonly');
      const store = tx.objectStore(STORE_CREDENCIALES);
      const request = store.get(normalized);
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  async function getAllCredentials() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_CREDENCIALES, 'readonly');
      const store = tx.objectStore(STORE_CREDENCIALES);
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  async function validateCredential(username, password) {
    const normalized = (username || '').trim().toLowerCase();
    if (!normalized) return null;
    const candidateHash = await hashCredential(normalized, password || '');
    const record = await getCredential(normalized);
    if (record && record.hash === candidateHash) {
      return record;
    }
    return null;
  }

  async function removeCredential(username) {
    const normalized = (username || '').trim().toLowerCase();
    if (!normalized) return;
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_CREDENCIALES, 'readwrite');
      const store = tx.objectStore(STORE_CREDENCIALES);
      const request = store.delete(normalized);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
      tx.oncomplete = () => db.close();
      tx.onabort = () => db.close();
      tx.onerror = () => db.close();
    });
  }

  window.asistenciaPWA = {
    init,
    queueSubmission,
    syncPendingRequests,
    onPendingChange(callback) {
      if (typeof callback === 'function') {
        pendingListeners.push(callback);
        updatePendingCount();
      }
    },
    auth: {
      saveCredential,
      getCredential,
      getAllCredentials,
      validateCredential,
      removeCredential
    }
  };
})();
