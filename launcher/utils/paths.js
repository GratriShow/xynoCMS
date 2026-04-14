const path = require('node:path');

function sanitizeSlug(s) {
  return String(s || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace(/[^a-z0-9_-]/g, '');
}

function getLauncherRootFolderName(app) {
  const envSlug = sanitizeSlug(process.env.LAUNCHER_SLUG);
  const baseSlug = envSlug || sanitizeSlug(app.getName()) || 'launcher';
  return `.${baseSlug}Launcher`;
}

function getLauncherPaths(app) {
  const rootName = getLauncherRootFolderName(app);
  const rootDir = path.join(app.getPath('appData'), rootName);

  return {
    rootDir,
    modsDir: path.join(rootDir, 'mods'),
    configDir: path.join(rootDir, 'config'),
    assetsDir: path.join(rootDir, 'assets'),
    versionsDir: path.join(rootDir, 'versions'),
    librariesDir: path.join(rootDir, 'libraries'),
    installersDir: path.join(rootDir, 'installers'),
  };
}

module.exports = {
  getLauncherPaths,
};
