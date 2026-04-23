<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';

/**
 * POST /dashboard/update_marketplace_settings.php
 *
 * Persists the per-launcher marketplace settings JSON, filtered by ownership
 * so that a tenant can never store/use a setting for an item they don't own.
 */

$user = require_login();

if (!is_post()) {
    redirect('/dashboard.php');
}

if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Jeton CSRF invalide — réessaie depuis le dashboard.');
    redirect('/dashboard.php');
}

$launcherUuid = trim((string)($_POST['launcher_uuid'] ?? ''));
if ($launcherUuid === '') {
    flash_set('error', 'Launcher introuvable.');
    redirect('/dashboard.php');
}

try {
    $pdo = db();

    // Ownership
    $ownStmt = $pdo->prepare('SELECT id FROM launchers WHERE uuid = ? AND user_id = ? LIMIT 1');
    $ownStmt->execute([$launcherUuid, $user['id']]);
    $row = $ownStmt->fetch();
    if (!$row) {
        flash_set('error', 'Accès refusé.');
        redirect('/dashboard.php');
    }
    $launcherId = (int)($row['id'] ?? 0);

    // Only accept sections for items this launcher owns.
    $ownedKeys = array_fill_keys(api_marketplace_owned_keys($launcherId), true);
    $existing  = api_marketplace_settings_get($launcherId);
    $out       = $existing; // start from existing, overwrite only allowed sections

    $post = $_POST;

    // Section markers: if the form posts `sections[]=copyright&sections[]=colors`,
    // we only touch those sections and leave the rest untouched. This lets the
    // dashboard split marketplace settings across several tabs/forms without
    // accidentally wiping fields that are not in the submitted form. When
    // `sections[]` is absent we keep the legacy "touch every owned section" behaviour.
    $postedSections = null;
    if (isset($post['sections']) && is_array($post['sections'])) {
        $postedSections = array_fill_keys(array_map('strval', $post['sections']), true);
    }
    $touching = function (string $section) use ($postedSections): bool {
        return $postedSections === null || isset($postedSections[$section]);
    };

    $hex = fn (string $v): string => preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '';

    if ($touching('copyright')) {
        if (isset($ownedKeys['remove_copyright'])) {
            $out['hide_copyright'] = !empty($post['hide_copyright']);
        } else {
            unset($out['hide_copyright']);
        }
    }

    if ($touching('colors')) {
        if (isset($ownedKeys['colors_custom'])) {
            $colors = is_array($post['colors'] ?? null) ? $post['colors'] : [];
            $out['colors'] = array_filter([
                'primary' => $hex(trim((string)($colors['primary'] ?? ''))),
                'accent'  => $hex(trim((string)($colors['accent']  ?? ''))),
                'bg'      => $hex(trim((string)($colors['bg']      ?? ''))),
                'surface' => $hex(trim((string)($colors['surface'] ?? ''))),
            ], fn ($v) => $v !== '');
        } else {
            unset($out['colors']);
        }
    }

    if ($touching('discord')) {
        if (isset($ownedKeys['discord_rpc_advanced'])) {
            $da = is_array($post['discord_advanced'] ?? null) ? $post['discord_advanced'] : [];
            $clientId = trim((string)($da['client_id'] ?? ''));
            if (!preg_match('/^\d{17,32}$/', $clientId)) $clientId = '';
            $buttons = [];
            if (is_array($da['buttons'] ?? null)) {
                foreach ($da['buttons'] as $b) {
                    if (!is_array($b)) continue;
                    $label = trim((string)($b['label'] ?? ''));
                    $url   = trim((string)($b['url'] ?? ''));
                    if ($label === '' || $url === '') continue;
                    if (strlen($label) > 32) $label = substr($label, 0, 32);
                    if (!preg_match('#^https?://#i', $url) || strlen($url) > 512) continue;
                    $buttons[] = ['label' => $label, 'url' => $url];
                    if (count($buttons) >= 2) break;
                }
            }
            $out['discord_advanced'] = [
                'client_id' => $clientId,
                'details'   => substr(trim((string)($da['details'] ?? '')), 0, 128),
                'state'     => substr(trim((string)($da['state']   ?? '')), 0, 128),
                'buttons'   => $buttons,
            ];
        } else {
            unset($out['discord_advanced']);
        }
    }

    if ($touching('anticheat')) {
        if (isset($ownedKeys['anticheat_advanced'])) {
            $aa = is_array($post['anticheat_advanced'] ?? null) ? $post['anticheat_advanced'] : [];
            $blacklistRaw = (string)($aa['process_blacklist'] ?? '');
            $blacklist = [];
            foreach (preg_split('/\R+/', $blacklistRaw) ?: [] as $line) {
                $line = strtolower(trim((string)$line));
                if ($line !== '' && strlen($line) <= 128) $blacklist[] = $line;
            }
            $out['anticheat_advanced'] = [
                'require_sha256'    => !empty($aa['require_sha256']),
                'process_blacklist' => array_values(array_unique($blacklist)),
            ];
        } else {
            unset($out['anticheat_advanced']);
        }
    }

    if ($touching('file_protection')) {
        if (isset($ownedKeys['file_protection'])) {
            $fp = is_array($post['file_protection'] ?? null) ? $post['file_protection'] : [];
            $out['file_protection'] = ['enabled' => !empty($fp['enabled'])];
        } else {
            unset($out['file_protection']);
        }
    }

    if ($touching('rest_api')) {
        if (isset($ownedKeys['rest_api'])) {
            $ra = is_array($post['rest_api'] ?? null) ? $post['rest_api'] : [];
            $out['rest_api'] = ['enabled' => !empty($ra['enabled'])];
        } else {
            unset($out['rest_api']);
        }
    }

    if ($touching('shop')) {
        if (isset($ownedKeys['shop'])) {
            $sh = is_array($post['shop'] ?? null) ? $post['shop'] : [];
            $url = trim((string)($sh['url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $url = '';
            $out['shop'] = ['url' => $url];
        } else {
            unset($out['shop']);
        }
    }

    if ($touching('music')) {
        if (isset($ownedKeys['music'])) {
            $mu = is_array($post['music'] ?? null) ? $post['music'] : [];
            $url = trim((string)($mu['url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $url = '';
            $volume = (float)($mu['volume'] ?? 0.5);
            if ($volume < 0) $volume = 0.0;
            if ($volume > 1) $volume = 1.0;
            $out['music'] = [
                'url'    => $url,
                'loop'   => !empty($mu['loop']),
                'volume' => round($volume, 2),
            ];
        } else {
            unset($out['music']);
        }
    }

    if ($touching('multi_account')) {
        if (isset($ownedKeys['multi_account'])) {
            $ma = is_array($post['multi_account'] ?? null) ? $post['multi_account'] : [];
            $out['multi_account'] = ['enabled' => !empty($ma['enabled'])];
        } else {
            unset($out['multi_account']);
        }
    }

    if ($touching('popup_promo')) {
        if (isset($ownedKeys['popup_promo'])) {
            $pp = is_array($post['popup_promo'] ?? null) ? $post['popup_promo'] : [];
            $html = (string)($pp['html'] ?? '');
            if (strlen($html) > 2000) $html = substr($html, 0, 2000);
            $until = trim((string)($pp['until'] ?? ''));
            if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $until)) $until = '';
            $out['popup_promo'] = ['html' => $html, 'until' => $until];
        } else {
            unset($out['popup_promo']);
        }
    }

    if ($touching('countdown')) {
        if (isset($ownedKeys['countdown'])) {
            $cd = is_array($post['countdown'] ?? null) ? $post['countdown'] : [];
            $title = trim((string)($cd['title'] ?? ''));
            if (strlen($title) > 128) $title = substr($title, 0, 128);
            $date = trim((string)($cd['date'] ?? ''));
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $date)) $date = '';
            $out['countdown'] = ['title' => $title, 'date' => $date];
        } else {
            unset($out['countdown']);
        }
    }

    if (!api_marketplace_settings_save($launcherId, $out)) {
        flash_set('error', 'Impossible d’enregistrer les paramètres marketplace.');
    } else {
        flash_set('success', 'Paramètres marketplace enregistrés.');
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'launcher_marketplace_settings') !== false
        || strpos($msg, "doesn't exist") !== false
        || strpos($msg, 'does not exist') !== false) {
        flash_set(
            'error',
            'Les tables marketplace sont manquantes. Importe `migrations_v3.sql` depuis la section SQL du dashboard.'
        );
        redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=monitoring#tab-monitoring');
    }
    flash_set('error', 'Erreur base de données : ' . $msg);
}

$returnTab = trim((string)($_POST['return_tab'] ?? ''));
if (!preg_match('/^[a-z0-9_-]{1,32}$/', $returnTab)) {
    $returnTab = 'marketplace';
}
redirect('/dashboard.php?launcher=' . urlencode($launcherUuid) . '&tab=' . $returnTab . '#tab-' . $returnTab);
