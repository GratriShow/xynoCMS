/**
 * XynoLauncherAPI.js
 *
 * Implémentation concrète de AbstractLauncherAPI pour le backend Xyno.
 * Cette classe encapsule TOUS les détails spécifiques à Xyno:
 * - HMAC-SHA256 signing
 * - Extension proxy pattern
 * - Custom Bearer auth proxy
 * - Heartbeat gateway
 *
 * Le launcher-core n'a JAMAIS besoin de connaître ces détails.
 */

'use strict';

const AbstractLauncherAPI = require('../../launcher-core/services/AbstractLauncherAPI');
const https = require('node:https');
const http = require('node:http');
const crypto = require('node:crypto');

class XynoLauncherAPI extends AbstractLauncherAPI {
  /**
   * @param {Object} config
   * @param {string} config.apiBaseUrl - HTTPS endpoint of Xyno backend
   * @param {string} config.launcherUuid - Launcher identifier
   * @param {string} config.launcherKey - Launcher secret (never exposed)
   */
  constructor(config) {
    super();
    this.apiBaseUrl = config.apiBaseUrl;
    this.launcherUuid = config.launcherUuid;
    this.launcherKey = config.launcherKey;
    this.timeoutMs = config.timeoutMs || 10000;

    // Validate required config
    if (!this.apiBaseUrl || !this.launcherUuid || !this.launcherKey) {
      throw new Error('XynoLauncherAPI requires apiBaseUrl, launcherUuid, and launcherKey');
    }

    // Ensure HTTPS
    if (!this.apiBaseUrl.startsWith('https://')) {
      throw new Error('apiBaseUrl must use HTTPS');
    }
  }

  /**
   * Sign request using HMAC-SHA256
   * @private
   */
  _signRequest(path, method, body) {
    const timestamp = Math.floor(Date.now() / 1000);
    const payload = `${method}|${path}|${timestamp}|${this.launcherUuid}|${body || ''}`;
    const signature = crypto
      .createHmac('sha256', this.launcherKey)
      .update(payload)
      .digest('hex');

    return { signature, timestamp };
  }

  /**
   * Make authenticated HTTP request to Xyno backend
   * @private
   */
  async _request(path, method = 'GET', body = null) {
    const { signature, timestamp } = this._signRequest(path, method, body);
    const url = new URL(path, this.apiBaseUrl).toString();

    const requestFn = url.startsWith('https://') ? https.request : http.request;
    const headers = {
      'Accept': 'application/json',
      'X-Launcher-UUID': this.launcherUuid,
      'X-Launcher-Signature': signature,
      'X-Launcher-Timestamp': String(timestamp),
    };

    if (body) {
      headers['Content-Type'] = 'application/json; charset=utf-8';
    }

    return new Promise((resolve, reject) => {
      const req = requestFn(url, { method, headers, timeout: this.timeoutMs }, (res) => {
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
          resolve({ statusCode: res.statusCode || 0, json, text });
        });
      });

      req.on('timeout', () => {
        const err = new Error('Request timeout');
        err.code = 'ETIMEDOUT';
        req.destroy(err);
      });

      req.on('error', reject);
      if (body) req.write(body);
      req.end();
    });
  }

  /**
   * Implement: getStatus
   */
  async getStatus() {
    const { json, statusCode } = await this._request('/api/v2/status.php');

    if (statusCode !== 200 || !json) {
      throw new Error(`Status check failed: ${statusCode}`);
    }

    return {
      status: json.status === 'active' ? 'active' : 'inactive',
      name: json.launcher?.name || '',
      news: json.news || [],
      config: json.config || {},
      branding: json.branding || null,
      extensions: json.extensions || [],
      auth: json.auth || null,
      marketplace: json.marketplace || null,
    };
  }

  /**
   * Implement: getExtension
   * Uses the proxy pattern: client asks Xyno, Xyno returns signed download URL
   */
  async getExtension(extensionKey) {
    // Validate key format
    if (!/^[a-z0-9_]{1,64}$/.test(extensionKey)) {
      throw new Error('Invalid extension key format');
    }

    const path = `/api/launcher_ext.php?ext=${encodeURIComponent(extensionKey)}`;
    const { json, statusCode } = await this._request(path);

    if (statusCode !== 200 || !json) {
      throw new Error(`Extension fetch failed: ${statusCode}`);
    }

    return json;
  }

  /**
   * Implement: loginCustom
   * Custom email+password auth via proxy to avoid exposing implementation
   */
  async loginCustom(credentials) {
    const body = JSON.stringify({
      email: credentials.email,
      password: credentials.password,
    });

    const { json, statusCode } = await this._request(
      '/api/launcher_auth.php',
      'POST',
      body
    );

    if (statusCode !== 200 || !json || !json.token) {
      throw new Error('Custom auth failed');
    }

    return {
      token: json.token,
      expiresIn: json.expires_in || 3600,
    };
  }

  /**
   * Implement: verifyCustomToken
   */
  async verifyCustomToken(token) {
    const body = JSON.stringify({ token });

    const { json, statusCode } = await this._request(
      '/api/launcher_auth_verify.php',
      'POST',
      body
    );

    if (statusCode !== 200 || !json) {
      return { valid: false, user: null };
    }

    return {
      valid: !!json.valid,
      user: json.user || null,
    };
  }

  /**
   * Implement: downloadFile
   * Note: In practice, launcher-core will handle the actual download.
   * This method just validates the request is allowed.
   */
  async downloadFile(url, filePath, onProgress) {
    // Validate the URL is from our allowed origin
    try {
      const urlObj = new URL(url);
      const baseObj = new URL(this.apiBaseUrl);
      
      // Allow downloads from same origin OR CDN domains configured in Xyno
      // For now, just verify HTTPS
      if (urlObj.protocol !== 'https:') {
        throw new Error('Download URLs must use HTTPS');
      }
    } catch (err) {
      throw new Error('Invalid download URL');
    }

    // launcher-core will handle the actual download
    // We just validated security constraints here
    return ''; // Return empty SHA1 - will be computed by downloader
  }

  /**
   * Implement: checkForUpdate
   */
  async checkForUpdate() {
    const { json, statusCode } = await this._request('/api/v2/launcher_update_check.php');

    if (statusCode !== 200 || !json) {
      return null;
    }

    // Return null if no update available, or update info
    return json.update_available ? json : null;
  }

  /**
   * Implement: applyUpdate
   */
  async applyUpdate(zipPath) {
    // This would be handled by launcher-core's update service
    // Xyno integration just notifies the backend
    await this._request('/api/v2/launcher_update_applied.php', 'POST', '{}');
  }

  /**
   * Implement: sendHeartbeat
   */
  async sendHeartbeat() {
    const body = JSON.stringify({
      version: process.env.LAUNCHER_VERSION || '1.0.0',
      user_count: 1, // Could be tracked by launcher-core
    });

    try {
      await this._request('/api/launcher_heartbeat.php', 'POST', body);
    } catch {
      // Heartbeat failure is non-critical
    }
  }

  /**
   * Implement: getLocalizedStrings
   */
  async getLocalizedStrings(locale) {
    const { json, statusCode } = await this._request(
      `/api/v2/launcher_i18n.php?lang=${encodeURIComponent(locale)}`
    );

    if (statusCode === 200 && json) {
      return json;
    }

    return {};
  }
}

module.exports = XynoLauncherAPI;
