<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../api/utils.php';
require_once __DIR__ . '/../api/email_helpers.php';

/**
 * Renvoie l'utilisateur courant si c'est un admin, sinon redirige.
 */
function require_admin(): array
{
    $user = require_login();
    $pdo  = db();
    try {
        $st = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user['id']]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        flash_set('error', 'Migration v5 manquante (is_admin).');
        redirect('/dashboard.php');
    }
    if (!$row || (int)($row['is_admin'] ?? 0) !== 1) {
        flash_set('error', 'Accès admin refusé.');
        redirect('/dashboard.php');
    }
    return $user;
}

function admin_log(int $adminId, string $action, string $targetType = '', ?int $targetId = null, ?string $notes = null): void
{
    try {
        $pdo = db();
        $st = $pdo->prepare(
            'INSERT INTO admin_actions (admin_id, action, target_type, target_id, notes, ip, created_at) '
          . 'VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $st->execute([$adminId, $action, $targetType, $targetId, $notes, api_client_ip()]);
    } catch (Throwable $e) { /* never break the flow */ }
}

/**
 * Affiche la navbar admin commune.
 */
function admin_render_nav(string $active = ''): void
{
    // Use absolute paths so the navbar works regardless of how the admin
    // section is being served (subdir, alias, custom rewrites, etc.).
    $items = [
        'dashboard'     => ['Vue d\'ensemble', '/admin/index.php'],
        'users'         => ['Utilisateurs',    '/admin/users.php'],
        'launchers'     => ['Launchers',       '/admin/launchers.php'],
        'subscriptions' => ['Abonnements',     '/admin/subscriptions.php'],
        'gifts'         => ['Cadeaux',         '/admin/gifts.php'],
        'emails'        => ['Logs emails',     '/admin/emails.php'],
        'audit'         => ['Audit log',       '/admin/audit.php'],
    ];
    echo '<header class="navbar"><div class="container nav-inner">';
    echo '<a class="brand" href="/index.php" aria-label="XynoLauncher"><span class="brand-mark" aria-hidden="true"></span><span>XynoLauncher · Admin</span></a>';
    echo '<nav class="nav-links" aria-label="Navigation admin">';
    foreach ($items as $k => $it) {
        $cls = $k === $active ? ' style="color:#a78bfa;font-weight:700"' : '';
        echo '<a href="' . e($it[1]) . '"' . $cls . '>' . e($it[0]) . '</a>';
    }
    echo '</nav>';
    echo '<div class="nav-actions">';
    echo '<button class="btn btn-ghost" type="button" onclick="window.__xynoOpenCmdK&&window.__xynoOpenCmdK()" aria-label="Recherche (Cmd+K)" title="Recherche (Cmd+K / Ctrl+K)" style="font-family:ui-monospace,monospace;font-size:12px;letter-spacing:.5px">⌘K</button>';
    echo '<a class="btn btn-ghost" href="/dashboard.php">Mon dashboard</a>';
    echo '<a class="btn" href="/auth/logout.php">Se déconnecter</a>';
    echo '</div>';
    echo '</div></header>';
    admin_render_cmdk();
}

/**
 * Palette de recherche Cmd+K. Injecte du HTML + JS auto-suffisant.
 * Appelée automatiquement depuis admin_render_nav().
 */
function admin_render_cmdk(): void
{
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
?>
<div id="xynoCmdK" role="dialog" aria-modal="true" aria-label="Recherche admin"
  style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);">
  <div style="max-width:640px;margin:80px auto 0;background:#0c0c14;border:1px solid rgba(255,255,255,.1);border-radius:16px;
    box-shadow:0 20px 60px rgba(0,0,0,.5);overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:10px">
      <span style="color:#8a8aa0;font-size:18px">🔍</span>
      <input id="xynoCmdKInput" type="text" autocomplete="off" placeholder="Rechercher email, UUID, sub_id, sujet..."
        style="flex:1;background:transparent;border:0;color:#fff;font-size:16px;outline:none;font-family:Inter,system-ui,sans-serif" />
      <kbd style="color:#8a8aa0;font-size:11px;font-family:ui-monospace,monospace;border:1px solid rgba(255,255,255,.15);padding:2px 6px;border-radius:4px">ESC</kbd>
    </div>
    <div id="xynoCmdKResults" style="max-height:60vh;overflow-y:auto;padding:6px"></div>
    <div style="padding:8px 18px;border-top:1px solid rgba(255,255,255,.06);color:#8a8aa0;font-size:12px;display:flex;gap:14px">
      <span><kbd style="font-family:ui-monospace,monospace">↑</kbd> <kbd style="font-family:ui-monospace,monospace">↓</kbd> Naviguer</span>
      <span><kbd style="font-family:ui-monospace,monospace">↵</kbd> Ouvrir</span>
      <span style="margin-left:auto">Users · Launchers · Subs · Emails</span>
    </div>
  </div>
</div>
<script>
(function(){
  var $modal = document.getElementById('xynoCmdK');
  var $input = document.getElementById('xynoCmdKInput');
  var $res   = document.getElementById('xynoCmdKResults');
  var idx = 0, items = [];
  var debounceT = 0;

  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }

  function highlight(){
    var rows = $res.querySelectorAll('.xynoCmdKRow');
    rows.forEach(function(el, i){
      el.style.background = (i === idx) ? 'rgba(124,58,237,.18)' : 'transparent';
    });
    var sel = rows[idx];
    if (sel) sel.scrollIntoView({block:'nearest'});
  }

  function render(rows){
    if (!rows || !rows.length) {
      $res.innerHTML = '<div style="padding:24px;text-align:center;color:#8a8aa0;font-size:13px">Aucun résultat</div>';
      items = [];
      return;
    }
    items = rows;
    idx = 0;
    var html = rows.map(function(r, i){
      return '<a class="xynoCmdKRow" data-idx="' + i + '" href="' + r.url + '"' +
             ' style="display:flex;flex-direction:column;gap:2px;padding:10px 14px;text-decoration:none;color:#fff;border-radius:8px;cursor:pointer">' +
             '<div style="font-size:14px">' + escapeHtml(r.label) + '</div>' +
             '<div style="font-size:12px;color:#8a8aa0">' + escapeHtml(r.sublabel || '') + '</div></a>';
    }).join('');
    $res.innerHTML = html;
    highlight();
  }

  function open(){
    $modal.style.display = 'block';
    setTimeout(function(){ $input.focus(); $input.select(); }, 10);
    if ($input.value.length >= 2) doSearch();
  }
  function close(){ $modal.style.display = 'none'; }

  function doSearch(){
    var q = $input.value.trim();
    if (q.length < 2) { $res.innerHTML = '<div style="padding:24px;text-align:center;color:#8a8aa0;font-size:13px">Tape au moins 2 caractères…</div>'; items = []; return; }
    fetch('/admin/search.php?q=' + encodeURIComponent(q), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){ render(j && j.results ? j.results : []); })
      .catch(function(){ $res.innerHTML = '<div style="padding:24px;text-align:center;color:#fca5a5;font-size:13px">Erreur réseau</div>'; });
  }

  window.__xynoOpenCmdK = open;

  document.addEventListener('keydown', function(e){
    var isCmdK = (e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey);
    if (isCmdK) { e.preventDefault(); open(); return; }
    if ($modal.style.display !== 'block') return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length){ idx = Math.min(idx+1, items.length-1); highlight(); } }
    else if (e.key === 'ArrowUp')   { e.preventDefault(); if (items.length){ idx = Math.max(idx-1, 0); highlight(); } }
    else if (e.key === 'Enter')     { if (items.length){ e.preventDefault(); window.location.href = items[idx].url; } }
  });

  $input.addEventListener('input', function(){
    clearTimeout(debounceT);
    debounceT = setTimeout(doSearch, 180);
  });

  $modal.addEventListener('click', function(e){
    if (e.target === $modal) close();
  });
})();
</script>
<?php
}

function admin_render_footer(): void
{
    echo '<footer class="footer"><div class="container footer-grid">';
    echo '<div><div class="brand" style="margin-bottom:10px"><span class="brand-mark" aria-hidden="true"></span><span>XynoLauncher</span></div><p class="small">Console admin réservée à l\'équipe XynoWeb.</p></div>';
    echo '<div></div><div></div><div></div>';
    echo '</div></footer>';
}
