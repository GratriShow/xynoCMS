function isPlainObject(v) {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function isValidSha1(hex) {
  return typeof hex === 'string' && /^[a-f0-9]{40}$/i.test(hex);
}

function isSafeRelativePath(p) {
  if (typeof p !== 'string') return false;
  if (!p) return false;
  if (p.includes('\\')) return false;
  if (p.startsWith('/')) return false;
  if (p.includes('\0')) return false;
  if (p.split('/').some((seg) => seg === '' || seg === '.' || seg === '..')) return false;
  return true;
}

function isAllowedTopLevel(p) {
  return (
    p.startsWith('mods/') ||
    p.startsWith('config/') ||
    p.startsWith('assets/')
  );
}

function parseManifest(raw, { apiBaseUrl }) {
  if (!isPlainObject(raw)) {
    throw new Error('Invalid manifest (not an object)');
  }

  const launcher = isPlainObject(raw.launcher) ? raw.launcher : {};
  const name = typeof launcher.name === 'string' ? launcher.name : '';
  const version = typeof launcher.version === 'string' ? launcher.version : '';
  const loader = typeof launcher.loader === 'string' ? launcher.loader : '';
  const theme = typeof launcher.theme === 'string' ? launcher.theme : '';

  const filesRaw = Array.isArray(raw.files) ? raw.files : null;
  if (!filesRaw) {
    throw new Error('Invalid manifest (missing files[])');
  }

  const allowedOrigin = new URL(apiBaseUrl).origin;

  const files = [];
  const seen = new Set();
  for (const f of filesRaw) {
    if (!isPlainObject(f)) continue;
    const path = typeof f.path === 'string' ? f.path.trim().replace(/\\/g, '/') : '';
    const url = typeof f.url === 'string' ? f.url.trim() : '';
    const hash = typeof f.hash === 'string' ? f.hash.trim().toLowerCase() : '';
    const size = Number.isFinite(f.size) ? f.size : Number(f.size);

    if (!isSafeRelativePath(path)) {
      throw new Error(`Invalid manifest file path: ${path || '(empty)'}`);
    }
    if (!isAllowedTopLevel(path)) {
      throw new Error(`Forbidden manifest path (must be mods/config/assets/versions): ${path}`);
    }
    if (!url) {
      throw new Error(`Invalid manifest url for: ${path}`);
    }
    let parsedUrl;
    try {
      parsedUrl = new URL(url);
    } catch {
      throw new Error(`Invalid manifest url for: ${path}`);
    }
    if (parsedUrl.origin !== allowedOrigin) {
      throw new Error(`Forbidden download origin for ${path}: ${parsedUrl.origin}`);
    }
    if (hash && !isValidSha1(hash)) {
      throw new Error(`Invalid sha1 for ${path}`);
    }
    if (!Number.isFinite(size) || size < 0) {
      throw new Error(`Invalid size for ${path}`);
    }

    if (seen.has(path)) {
      throw new Error(`Duplicate manifest path: ${path}`);
    }
    seen.add(path);

    files.push({ path, url, hash, size });
  }

  const totalSize = Number.isFinite(raw.total_size) ? raw.total_size : Number(raw.total_size || 0);

  return {
    launcher: { name, version, loader, theme: String(theme || '').trim() || 'default' },
    fileCount: files.length,
    totalSize: Number.isFinite(totalSize) ? totalSize : 0,
    files,
  };
}

module.exports = {
  parseManifest,
};
