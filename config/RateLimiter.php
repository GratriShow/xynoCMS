<?php
/**
 * RateLimiter — Limitation du taux de requêtes API
 *
 * Utilise APCu si disponible (recommandé en prod), sinon fichiers temporaires.
 * Algorithme : fenêtre glissante par IP + clé.
 *
 * Usage :
 *   RateLimiter::check('auth', 5, 60);   // max 5 tentatives / 60s
 *   RateLimiter::check('api',  60, 60);  // max 60 req / 60s
 */

declare(strict_types=1);

class RateLimiter
{
    // ── Vérification et comptage ──────────────────────────────────────────────

    /**
     * Vérifie si la requête est dans les limites et incrémente le compteur.
     *
     * @param string $key       Identifiant du groupe de règles (ex: 'auth', 'api')
     * @param int    $maxHits   Nombre max de requêtes autorisées dans la fenêtre
     * @param int    $windowSec Durée de la fenêtre glissante en secondes
     * @param string $ip        IP source (par défaut : IP du client actuel)
     *
     * @throws \RuntimeException avec HTTP 429 si la limite est dépassée
     */
    public static function check(
        string $key,
        int    $maxHits   = 60,
        int    $windowSec = 60,
        string $ip        = ''
    ): void {
        $ip    = $ip ?: self::clientIp();
        $cacheKey = "rl:{$key}:{$ip}";

        $hits = self::increment($cacheKey, $windowSec);

        if ($hits > $maxHits) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $windowSec);
            header('X-RateLimit-Limit: ' . $maxHits);
            header('X-RateLimit-Remaining: 0');
            echo json_encode([
                'ok'    => false,
                'error' => "Trop de requêtes. Réessayez dans {$windowSec} secondes.",
                'code'  => 'RATE_LIMITED',
            ]);
            exit;
        }

        // Headers informatifs
        header('X-RateLimit-Limit: ' . $maxHits);
        header('X-RateLimit-Remaining: ' . max(0, $maxHits - $hits));
    }

    /**
     * Réinitialise le compteur pour une clé donnée (ex: après login réussi).
     */
    public static function reset(string $key, string $ip = ''): void
    {
        $ip       = $ip ?: self::clientIp();
        $cacheKey = "rl:{$key}:{$ip}";
        self::delete($cacheKey);
    }

    // ── Backend : APCu ou fichiers ────────────────────────────────────────────

    private static function increment(string $cacheKey, int $ttl): int
    {
        if (function_exists('apcu_fetch') && ini_get('apc.enabled')) {
            return self::apcuIncrement($cacheKey, $ttl);
        }
        return self::fileIncrement($cacheKey, $ttl);
    }

    private static function delete(string $cacheKey): void
    {
        if (function_exists('apcu_delete') && ini_get('apc.enabled')) {
            apcu_delete($cacheKey);
            return;
        }
        $file = self::cacheFile($cacheKey);
        if (file_exists($file)) @unlink($file);
    }

    // APCu ────────────────────────────────────────────────────────────────────

    private static function apcuIncrement(string $key, int $ttl): int
    {
        $success = false;
        apcu_add($key, 0, $ttl);
        return (int)apcu_inc($key, 1, $success);
    }

    // Fichiers (fallback) ─────────────────────────────────────────────────────

    private static function cacheFile(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return sys_get_temp_dir() . '/xynoweb_rl_' . $safe . '.json';
    }

    private static function fileIncrement(string $key, int $ttl): int
    {
        $file = self::cacheFile($key);
        $now  = time();

        $data = null;
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $data = json_decode($raw, true);
        }

        // Reset si fenêtre expirée
        if (!is_array($data) || ($data['expires'] ?? 0) <= $now) {
            $data = ['hits' => 0, 'expires' => $now + $ttl];
        }

        $data['hits']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $data['hits'];
    }

    // ── IP du client ──────────────────────────────────────────────────────────

    private static function clientIp(): string
    {
        // Respecte les proxies de confiance (Cloudflare, nginx, etc.)
        $candidates = [
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_REAL_IP',             // nginx proxy
            'HTTP_X_FORWARDED_FOR',       // Load balancers
            'REMOTE_ADDR',                // Connexion directe
        ];

        foreach ($candidates as $key) {
            $val = $_SERVER[$key] ?? '';
            if ($val === '') continue;
            // X-Forwarded-For peut contenir plusieurs IPs ; on prend la première
            $ip = trim(explode(',', $val)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
