'use strict';

const { contextBridge, ipcRenderer } = require('electron');

function on(channel, callback) {
  if (typeof callback !== 'function') return () => {};
  const listener = (_event, payload) => callback(payload);
  ipcRenderer.on(channel, listener);
  return () => ipcRenderer.removeListener(channel, listener);
}

contextBridge.exposeInMainWorld('launcher', {
  onStatus: (cb) => on('launcher:status', cb),
  onProgress: (cb) => on('launcher:progress', cb),
  onError: (cb) => on('launcher:error', cb),
  onInfo: (cb) => on('launcher:info', cb),
  onUx: (cb) => on('launcher:ux', cb),

  retry: () => ipcRenderer.invoke('launcher:retry'),
  play: () => ipcRenderer.invoke('launcher:play'),
  quit: () => ipcRenderer.invoke('launcher:quit'),

  openExternal: (url) => ipcRenderer.invoke('launcher:openExternal', url),

  // Extension proxy: fetch data for one enabled extension from Xyno backend.
  // Returns { ok:true, data:{key, source, data:{...}} } on success,
  // or { ok:false, error:string } on failure. Errors never expose upstream tokens.
  fetchExtension: (key) => ipcRenderer.invoke('launcher:fetchExtension', key),
});

contextBridge.exposeInMainWorld('auth', {
  onMsaCode: (cb) => on('auth:msaCode', cb),
  loginMicrosoft: () => ipcRenderer.invoke('auth:loginMicrosoft'),
  getSession: () => ipcRenderer.invoke('auth:getSession'),
  logout: () => ipcRenderer.invoke('auth:logout'),

  // Custom (Bearer API) auth: proxied via Xyno backend so the upstream api_key
  // never leaves the server. The renderer only sees the final token + profile.
  loginCustom: (email, password) => ipcRenderer.invoke('auth:loginCustom', { email, password }),
  verifyCustom: (token) => ipcRenderer.invoke('auth:verifyCustom', { token }),
});

// New stable UI-facing API (themes must use this)
contextBridge.exposeInMainWorld('launcherAPI', {
  /**
   * login({interactive})
   * - interactive=false: returns existing session if any
   * - interactive=true: starts Microsoft device code flow
   */
  login: async ({ interactive } = {}) => {
    if (interactive) return await ipcRenderer.invoke('auth:loginMicrosoft');
    return await ipcRenderer.invoke('auth:getSession');
  },

  logout: async () => await ipcRenderer.invoke('auth:logout'),
  play: async () => await ipcRenderer.invoke('launcher:play'),
  retry: async () => await ipcRenderer.invoke('launcher:retry'),
  quit: async () => await ipcRenderer.invoke('launcher:quit'),

  openExternal: async (url) => await ipcRenderer.invoke('launcher:openExternal', url),

  settings: {
    get: async () => await ipcRenderer.invoke('settings:get'),
    update: async (patch) => await ipcRenderer.invoke('settings:update', patch),
  },

  // Extension proxy (Xyno backend hides the upstream api_key).
  fetchExtension: async (key) => await ipcRenderer.invoke('launcher:fetchExtension', key),

  // Custom Bearer auth (Xyno backend hides the upstream api_key).
  auth: {
    loginCustom: async (email, password) => await ipcRenderer.invoke('auth:loginCustom', { email, password }),
    verifyCustom: async (token) => await ipcRenderer.invoke('auth:verifyCustom', { token }),
  },

  // Unified subscription for progress events
  progression: (handlers = {}) => {
    const off = [];
    if (handlers.onStatus) off.push(on('launcher:status', handlers.onStatus));
    if (handlers.onProgress) off.push(on('launcher:progress', handlers.onProgress));
    if (handlers.onError) off.push(on('launcher:error', handlers.onError));
    if (handlers.onInfo) off.push(on('launcher:info', handlers.onInfo));
    if (handlers.onUx) off.push(on('launcher:ux', handlers.onUx));
    if (handlers.onMsaCode) off.push(on('auth:msaCode', handlers.onMsaCode));

    return () => off.forEach((fn) => {
      try {
        if (typeof fn === 'function') fn();
      } catch {
        // ignore
      }
    });
  },
});
