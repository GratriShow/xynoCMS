/**
 * AbstractLauncherAPI.js
 *
 * Interface abstraite que toute intégration launcher doit implémenter.
 * Le launcher-core dépend UNIQUEMENT de cette interface, pas d'implémentations concrètes.
 *
 * Cela permet au même code launcher de fonctionner avec n'importe quel backend CMS.
 */

'use strict';

/**
 * Status response structure
 * @typedef {Object} LauncherStatus
 * @property {string} status - 'active' or 'inactive'
 * @property {string} [name] - Launcher display name
 * @property {Array<Object>} [news] - News items to display
 * @property {Object} [config] - Configuration overrides
 * @property {Object} [branding] - Branding customizations
 * @property {Array<Object>} [extensions] - Available extensions
 * @property {Object} [auth] - Auth configuration
 * @property {Object} [marketplace] - Marketplace info
 */

/**
 * Download info structure
 * @typedef {Object} DownloadInfo
 * @property {string} url - HTTPS download URL
 * @property {string} sha1 - SHA1 hash for verification
 * @property {number} size - File size in bytes
 * @property {string} [description] - Human-readable description
 */

class AbstractLauncherAPI {
  /**
   * Check license status and get public configuration
   * @returns {Promise<LauncherStatus>}
   * @throws {Error} Network or authentication errors
   */
  async getStatus() {
    throw new Error('getStatus() must be implemented by subclass');
  }

  /**
   * Get extension metadata and download URL
   * @param {string} extensionKey - Extension identifier (alphanumeric + underscore)
   * @returns {Promise<Object>} Extension metadata with download URL
   * @throws {Error} If extension not found or network error
   */
  async getExtension(extensionKey) {
    throw new Error('getExtension() must be implemented by subclass');
  }

  /**
   * Authenticate with custom credentials (email + password)
   * Returns a bearer token for subsequent requests
   * @param {Object} credentials - { email, password }
   * @returns {Promise<{token: string, expiresIn: number}>}
   * @throws {Error} Invalid credentials or server error
   */
  async loginCustom(credentials) {
    throw new Error('loginCustom() must be implemented by subclass');
  }

  /**
   * Verify a custom auth token is still valid
   * @param {string} token - Bearer token
   * @returns {Promise<{valid: boolean, user: Object}>}
   */
  async verifyCustomToken(token) {
    throw new Error('verifyCustomToken() must be implemented by subclass');
  }

  /**
   * Fetch a file via the API with progress callback
   * MUST support chunked/streaming responses for large files
   * @param {string} url - Download URL
   * @param {string} filePath - Local path to save to
   * @param {Function} onProgress - Callback: (bytesReceived, totalBytes) => void
   * @returns {Promise<string>} SHA1 hash of downloaded file for verification
   * @throws {Error} Download failed
   */
  async downloadFile(url, filePath, onProgress) {
    throw new Error('downloadFile() must be implemented by subclass');
  }

  /**
   * Check for launcher updates
   * @returns {Promise<Object|null>} Update info: { version, url, sha1 } or null if up-to-date
   */
  async checkForUpdate() {
    throw new Error('checkForUpdate() must be implemented by subclass');
  }

  /**
   * Apply a launcher update from a ZIP file
   * Called by auto-update service after download verification
   * @param {string} zipPath - Path to downloaded update ZIP
   * @returns {Promise<void>}
   */
  async applyUpdate(zipPath) {
    throw new Error('applyUpdate() must be implemented by subclass');
  }

  /**
   * Periodic heartbeat (optional)
   * Some backends may want to track active launchers
   * @returns {Promise<void>}
   */
  async sendHeartbeat() {
    // Default implementation: no-op
    return Promise.resolve();
  }

  /**
   * Get localized strings for UI (optional)
   * @param {string} locale - Language code (en-US, fr-FR, etc)
   * @returns {Promise<Object>} Key-value pairs for i18n
   */
  async getLocalizedStrings(locale) {
    // Default: return empty object (use built-in strings)
    return {};
  }
}

module.exports = AbstractLauncherAPI;
