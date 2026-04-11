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

async function getJson(url, { timeoutMs = 10_000, headers = {} } = {}) {
  const requestFn = getRequestFn(url);

  return await new Promise((resolve, reject) => {
    const req = requestFn(
      url,
      {
        method: 'GET',
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
          const body = Buffer.concat(chunks).toString('utf8');
          resolve({
            statusCode: res.statusCode || 0,
            headers: res.headers || {},
            body,
          });
        });
      }
    );

    req.on('timeout', () => {
      const err = new Error('Request timeout');
      err.code = 'ETIMEDOUT';
      req.destroy(err);
    });

    req.on('error', (err) => reject(err));
    req.end();
  });
}

async function getManifest(uuid, key, { apiBaseUrl, timeoutMs = 10_000 } = {}) {
  if (!apiBaseUrl || typeof apiBaseUrl !== 'string') {
    throw new Error('Missing apiBaseUrl');
  }
  const base = new URL(apiBaseUrl);
  const url = new URL('/api/manifest.php', base);
  url.searchParams.set('uuid', uuid);
  url.searchParams.set('key', key);

  const safeUrl = new URL(url);
  if (safeUrl.searchParams.has('uuid')) safeUrl.searchParams.set('uuid', '***');
  if (safeUrl.searchParams.has('key')) safeUrl.searchParams.set('key', '***');

  let res;
  try {
    res = await getJson(url, { timeoutMs });
  } catch (err) {
    if (isLikelyNetworkError(err)) {
      const e = new Error('API unreachable (offline/timeout)');
      e.code = err && err.code ? err.code : undefined;
      e.safeUrl = safeUrl.toString();
      throw e;
    }
    throw err;
  }

  if (res.statusCode !== 200) {
    let payload;
    try {
      payload = JSON.parse(res.body);
    } catch {
      payload = null;
    }
    const msg = payload && payload.error ? String(payload.error) : `HTTP ${res.statusCode}`;
    throw new Error(`Manifest API error: ${msg}`);
  }

  let json;
  try {
    json = JSON.parse(res.body);
  } catch (err) {
    throw new Error('Invalid JSON from API');
  }

  return json;
}

async function getLauncherStatus(uuid, key, { apiBaseUrl, timeoutMs = 10_000 } = {}) {
  if (!apiBaseUrl || typeof apiBaseUrl !== 'string') {
    throw new Error('Missing apiBaseUrl');
  }
  const base = new URL(apiBaseUrl);
  const url = new URL('/api/launcher.php', base);
  url.searchParams.set('uuid', uuid);
  url.searchParams.set('key', key);

  const safeUrl = new URL(url);
  if (safeUrl.searchParams.has('uuid')) safeUrl.searchParams.set('uuid', '***');
  if (safeUrl.searchParams.has('key')) safeUrl.searchParams.set('key', '***');

  let res;
  try {
    res = await getJson(url, { timeoutMs });
  } catch (err) {
    if (isLikelyNetworkError(err)) {
      const e = new Error('API unreachable (offline/timeout)');
      e.code = err && err.code ? err.code : undefined;
      e.safeUrl = safeUrl.toString();
      throw e;
    }
    throw err;
  }

  let json;
  try {
    json = JSON.parse(res.body);
  } catch {
    json = null;
  }

  const status = json && typeof json.status === 'string' ? json.status.trim().toLowerCase() : '';

  // API may return {status:"inactive"} with 401 for invalid uuid/key.
  if (res.statusCode === 200 || (res.statusCode === 401 && status)) {
    return {
      ok: true,
      status: status || 'unknown',
      raw: json,
      httpStatus: res.statusCode,
    };
  }

  const msg = json && json.error ? String(json.error) : `HTTP ${res.statusCode}`;
  throw new Error(`Launcher API error: ${msg}`);
}

module.exports = {
  getManifest,
  getLauncherStatus,
};
