/**
 * launcher-core/index.js
 *
 * API publique du launcher-core générique.
 * Exporte tous les services sans dépendre d'aucune implémentation CMS.
 */

'use strict';

// Abstract interface that all API implementations must follow
const AbstractLauncherAPI = require('./services/AbstractLauncherAPI');

// Generic launcher services (never import concrete implementations here)
// These will be added as we refactor the current launcher

module.exports = {
  // Abstract interface
  AbstractLauncherAPI,

  // Services will be exported here
  // Examples:
  // MinecraftLauncher
  // FileDownloader
  // SessionManager
  // StateManager
  // etc.

  // Version
  version: '1.0.0',
};
