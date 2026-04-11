'use strict';

const crypto = require('node:crypto');
const { request } = require('node:https');
const { request: httpRequest } = require('node:http');

function isLikelyNetworkError(err) {
  const code = err && err.code;
  return (
    code === 'ENOTFOUND' ||
    code === 'ECONNREFUSED' ||
    code === 'ECONNRESET' ||
    code === 'EAI_AGAIN' ||
    code === 'ETIMEDOUT'
  );
}

function getRequestFn(url) {
  return url.protocol === 'http:' ? httpRequest : request;
}

function sha256Hex(s) {
  return crypto.createHash('sha256').update(s || '').digest('hex');
}

function hmacSha256Hex(keyHexText, data) {
  // Server uses the stored secret as a plain string key (hex text).
  return crypto.createHmac('sha256', String(keyHexText || ''), 'utf8').update(String(data || ''), 'utf8').digest('hex');
}

function hmacSha256HexRawKey(keyRaw, data) {
  return crypto.createHmac('sha256', String(keyRaw || ''), 'utf8').update(String(data || ''), 'utf8').digest('hex');
}

function nonceHex(bytes = 16) {
  return crypto.randomBytes(bytes).toString('hex');
}

async function httpJson(url, { method = 'GET', headers = {}, body = '', timeoutMs = 10_000 } = {}) {
  const requestFn = getRequestFn(url);

  return await new Promise((resolve, reject) => {
    const req = requestFn(
      url,
      {
        method,
        headers: {
          Accept: 'application/json',
          ...headers,
        },
        timeout: timeoutMs,
      },
      (res) => {
        const chunks = [];
        res.on('data', (d) => chunks.push(d));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch {
            json = null;
          }
          resolve({ statusCode: res.statusCode || 0, headers: res.headers || {}, text, json });
        });
      }
    );

    req.on('timeout', () => {
      const err = new Error('Request timeout');
      err.code = 'ETIMEDOUT';
      req.destroy(err);
    });

    req.on('error', (err) => reject(err));

    if (body) req.write(body);
    req.end();
  });
}

function normalizeBaseUrl(apiBaseUrl) {
  if (!apiBaseUrl || typeof apiBaseUrl !== 'string') throw new Error('Missing apiBaseUrl');
  return new URL(apiBaseUrl);
}

function safeApiError(prefix, res) {
  const payload = res && res.json && typeof res.json === 'object' ? res.json : null;
  const msg = payload && payload.error ? String(payload.error) : `HTTP ${res && res.statusCode ? res.statusCode : 0}`;
  return new Error(`${prefix}: ${msg}`);
}

function createClient({ apiBaseUrl, uuid, apiKey, timeoutMs = 10_000, integrityProvider = null } = {}) {
  const base = normalizeBaseUrl(apiBaseUrl);
  const launcherUuid = String(uuid || '').trim();
  const launcherKey = String(apiKey || '').trim();
  if (!launcherUuid) throw new Error('Missing uuid');
  if (!launcherKey) throw new Error('Missing apiKey');

  const state = {
    sessionId: '',
    sessionSecretHex: '',
    token: '',
  };

  function getIntegrity() {
    try {
      if (typeof integrityProvider !== 'function') return null;
      const v = integrityProvider();
      if (!v || typeof v !== 'object') return null;
      const asar = typeof v.asar_sha256 === 'string' ? v.asar_sha256.trim() : '';
      if (!asar) return null;
      return { asar_sha256: asar };
    } catch {
      return null;
    }
  }

  async function createSession() {
    const url = new URL('/api/v2/session.php', base);
    const ts = Math.floor(Date.now() / 1000);
    const nonce = nonceHex(16);
    const integrity = getIntegrity();
    const bodyObj = integrity ? { uuid: launcherUuid, ts, nonce, integrity } : { uuid: launcherUuid, ts, nonce };
    const body = JSON.stringify(bodyObj);
    const bodyHash = sha256Hex(body);
    const sigBase = `SESSION\n${launcherUuid}\n${ts}\n${nonce}\n${bodyHash}`;
    const sig = hmacSha256HexRawKey(launcherKey, sigBase);

    let res;
    try {
      res = await httpJson(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'X-Sig': sig,
        },
        body,
        timeoutMs,
      });
    } catch (err) {
      if (isLikelyNetworkError(err)) {
        const e = new Error('API unreachable (offline/timeout)');
        e.code = err && err.code ? err.code : undefined;
        throw e;
      }
      throw err;
    }

    if (res.statusCode !== 200 || !res.json || !res.json.ok) {
      throw safeApiError('Session API error', res);
    }

    const sess = res.json.session || null;
    const sid = sess && typeof sess.id === 'string' ? sess.id.trim() : '';
    const secret = sess && typeof sess.secret === 'string' ? sess.secret.trim() : '';
    const token = typeof res.json.token === 'string' ? res.json.token.trim() : '';
    if (!sid || !secret || !token) throw new Error('Session API error: invalid payload');

    state.sessionId = sid;
    state.sessionSecretHex = secret;
    state.token = token;

    return { ok: true, sessionId: sid };
  }

  async function ensureSession() {
    if (state.sessionId && state.sessionSecretHex && state.token) return;
    await createSession();
  }

  function signedHeaders({ method, url, body = '' }) {
    const ts = Math.floor(Date.now() / 1000);
    const nonce = nonceHex(16);
    const bodyHash = sha256Hex(body || '');
    const path = url.pathname;
    const baseStr = `${String(method).toUpperCase()}\n${path}\n${ts}\n${nonce}\n${bodyHash}`;
    const sig = hmacSha256Hex(state.sessionSecretHex, baseStr);

    return {
      Authorization: `Bearer ${state.token}`,
      'X-Session-Id': state.sessionId,
      'X-TS': String(ts),
      'X-Nonce': nonce,
      'X-Sig': sig,
    };
  }

  async function requestJson(path, { method = 'GET', bodyObj = null, timeoutMs: t = timeoutMs } = {}) {
    await ensureSession();

    const url = new URL(path, base);
    const body = bodyObj ? JSON.stringify(bodyObj) : '';
    const headers = signedHeaders({ method, url, body });

    let res;
    try {
      res = await httpJson(url, {
        method,
        headers: {
          ...headers,
          ...(body ? { 'Content-Type': 'application/json; charset=utf-8' } : {}),
        },
        body,
        timeoutMs: t,
      });
    } catch (err) {
      if (isLikelyNetworkError(err)) {
        const e = new Error('API unreachable (offline/timeout)');
        e.code = err && err.code ? err.code : undefined;
        throw e;
      }
      throw err;
    }

    if (res.statusCode === 401) {
      // Session/JWT expired: retry once with a fresh session.
      state.sessionId = '';
      state.sessionSecretHex = '';
      state.token = '';
      await ensureSession();
      return await requestJson(path, { method, bodyObj, timeoutMs: t });
    }

    if (res.statusCode !== 200) {
      throw safeApiError('v2 API error', res);
    }

    if (!res.json || typeof res.json !== 'object') {
      throw new Error('Invalid JSON from API');
    }

    return res.json;
  }

  function headersForUrl(urlString, { method = 'GET' } = {}) {
    if (!state.sessionId || !state.sessionSecretHex || !state.token) {
      // Caller must ensure session is ready.
      return null;
    }
    const url = new URL(urlString);
    return signedHeaders({ method, url, body: '' });
  }

  return {
    ensureSession,
    getStatus: async () => {
      const integrity = getIntegrity();
      return await requestJson('/api/v2/status.php', { method: 'POST', bodyObj: integrity ? { integrity } : {} });
    },
    getManifest: async () => await requestJson('/api/v2/manifest.php', { method: 'GET' }),
    mintPlayToken: async () => {
      const integrity = getIntegrity();
      return await requestJson('/api/v2/token.php', { method: 'POST', bodyObj: integrity ? { purpose: 'play', integrity } : { purpose: 'play' } });
    },
    headersForUrl,
  };
}

module.exports = {
  createClient,
};
