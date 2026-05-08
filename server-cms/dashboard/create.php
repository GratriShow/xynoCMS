<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>XynoServer — Créer un serveur</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= e(base_path()) ?>/assets/style.css" />
  <style>
    /* ── Wizard ── */
    .wizard { max-width: 760px; margin: 32px auto; padding: 0 var(--gutter); }
    .wizard-steps {
      display: flex; align-items: center; gap: 0;
      margin-bottom: 36px; padding: 0 4px;
    }
    .step-item {
      display: flex; align-items: center; gap: 0; flex: 1;
    }
    .step-circle {
      width: 36px; height: 36px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 700; flex-shrink: 0;
      border: 2px solid var(--d-border-2);
      background: var(--d-surface); color: var(--d-text-3);
      transition: all .25s;
    }
    .step-item.active   .step-circle { border-color: var(--accent); background: var(--accent); color: #fff; }
    .step-item.done     .step-circle { border-color: var(--success); background: var(--success); color: #fff; }
    .step-label {
      font-size: 12px; font-weight: 600; color: var(--d-text-3);
      margin-left: 8px; white-space: nowrap;
    }
    .step-item.active .step-label { color: var(--d-text); }
    .step-line {
      flex: 1; height: 2px;
      background: var(--d-border-2); margin: 0 8px;
      transition: background .25s;
    }
    .step-item.done + .step-item .step-line,
    .step-item.done .step-line { background: var(--success); }

    /* ── Cards ── */
    .wizard-card {
      background: var(--d-surface); border: 1px solid var(--d-border);
      border-radius: var(--d-radius-lg); padding: 32px;
    }
    .wizard-card-title {
      font-size: 20px; font-weight: 800; margin: 0 0 6px;
    }
    .wizard-card-sub {
      color: var(--d-text-2); font-size: 14px; margin: 0 0 28px;
    }
    .form-group { margin-bottom: 20px; }
    .form-label {
      display: block; font-size: 13px; font-weight: 600;
      color: var(--d-text); margin-bottom: 7px;
    }
    .form-label span { color: var(--d-text-3); font-weight: 400; margin-left: 4px; }
    .form-input {
      width: 100%; padding: 10px 14px; background: var(--d-elevated);
      border: 1px solid var(--d-border-2); border-radius: var(--d-radius);
      color: var(--d-text); font-size: 14px; font-family: inherit;
      transition: border-color .15s;
    }
    .form-input:focus { outline: none; border-color: var(--accent); }
    .form-input::placeholder { color: var(--d-text-3); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }

    /* ── Type selector ── */
    .type-grid {
      display: grid; grid-template-columns: repeat(5, 1fr);
      gap: 10px; margin-bottom: 4px;
    }
    @media (max-width: 600px) { .type-grid { grid-template-columns: repeat(3, 1fr); } }
    .type-card {
      border: 2px solid var(--d-border-2); border-radius: var(--d-radius);
      padding: 14px 8px; text-align: center; cursor: pointer;
      background: var(--d-elevated); transition: all .15s;
    }
    .type-card:hover { border-color: var(--accent); background: var(--d-surface-2); }
    .type-card.selected { border-color: var(--accent); background: var(--d-accent-soft); }
    .type-card input { display: none; }
    .type-icon { font-size: 24px; margin-bottom: 6px; }
    .type-name { font-size: 12px; font-weight: 700; color: var(--d-text); }
    .type-desc { font-size: 10px; color: var(--d-text-3); margin-top: 2px; line-height: 1.3; }

    /* ── Version selector ── */
    .version-list {
      display: flex; flex-wrap: wrap; gap: 8px;
      max-height: 180px; overflow-y: auto;
      padding: 12px; background: var(--d-elevated);
      border: 1px solid var(--d-border-2); border-radius: var(--d-radius);
    }
    .version-chip {
      padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
      border: 1px solid var(--d-border-2); background: var(--d-surface);
      cursor: pointer; transition: all .12s; color: var(--d-text-2);
    }
    .version-chip:hover { border-color: var(--accent); color: var(--d-text); }
    .version-chip.selected {
      border-color: var(--accent); background: var(--d-accent-soft);
      color: var(--d-text);
    }
    .loader-row {
      display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;
    }

    /* ── Config ── */
    .config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 500px) { .config-grid { grid-template-columns: 1fr; } }
    .toggle-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; background: var(--d-elevated);
      border: 1px solid var(--d-border-2); border-radius: var(--d-radius);
    }
    .toggle-label { font-size: 13px; font-weight: 600; color: var(--d-text); }
    .toggle-sub { font-size: 11px; color: var(--d-text-3); margin-top: 2px; }
    .toggle { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
    .toggle input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0; background: var(--d-border-2);
      border-radius: 22px; cursor: pointer; transition: background .2s;
    }
    .toggle-slider::after {
      content: ''; position: absolute; width: 16px; height: 16px;
      border-radius: 50%; background: #fff;
      top: 3px; left: 3px; transition: transform .2s;
    }
    .toggle input:checked + .toggle-slider { background: var(--accent); }
    .toggle input:checked + .toggle-slider::after { transform: translateX(18px); }

    /* ── Navigation ── */
    .wizard-nav {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 28px; padding-top: 20px;
      border-top: 1px solid var(--d-border);
    }
    .btn-wizard {
      padding: 11px 24px; font-size: 14px; font-weight: 600;
      border-radius: var(--d-radius); border: 1px solid var(--d-border-2);
      background: var(--d-elevated); color: var(--d-text);
      cursor: pointer; transition: all .15s; font-family: inherit;
    }
    .btn-wizard:hover { background: var(--surface-3); }
    .btn-wizard-primary {
      background: var(--grad-primary); border-color: transparent; color: #fff;
    }
    .btn-wizard-primary:hover { filter: brightness(1.12); }
    .btn-wizard:disabled { opacity: .4; cursor: default; }

    /* ── Summary ── */
    .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .summary-item {
      background: var(--d-elevated); border: 1px solid var(--d-border);
      border-radius: var(--d-radius); padding: 12px 16px;
    }
    .summary-key { font-size: 11px; color: var(--d-text-3); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
    .summary-val { font-size: 15px; font-weight: 700; color: var(--d-text); margin-top: 3px; }
    .loading { display: flex; align-items: center; gap: 8px; color: var(--d-text-2); font-size: 13px; }
    .spinner {
      width: 16px; height: 16px; border: 2px solid var(--d-border-2);
      border-top-color: var(--accent); border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .error-msg { color: #ff4d6a; font-size: 13px; margin-top: 8px; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="container nav-inner">
    <a href="<?= e(base_path()) ?>/" class="brand">
      <div class="brand-mark" style="width:32px;height:32px;background:var(--grad-primary);border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;font-size:14px;">X</div>
      <span>XynoCMS</span>
    </a>
    <div style="display:flex;align-items:center;gap:16px;">
      <a href="<?= e(server_cms_base()) ?>/dashboard/servers.php" style="color:var(--d-text-2);font-size:14px;">← Mes serveurs</a>
    </div>
  </div>
</nav>

<div class="wizard">

  <!-- Steps -->
  <div class="wizard-steps">
    <div class="step-item active" id="step-ind-1">
      <div class="step-circle">1</div>
      <div class="step-label">Type & version</div>
      <div class="step-line"></div>
    </div>
    <div class="step-item" id="step-ind-2">
      <div class="step-circle">2</div>
      <div class="step-label">Configuration</div>
      <div class="step-line"></div>
    </div>
    <div class="step-item" id="step-ind-3">
      <div class="step-circle">3</div>
      <div class="step-label">Récapitulatif</div>
    </div>
  </div>

  <!-- ── Étape 1 : Type & version ── -->
  <div id="step-1">
    <div class="wizard-card">
      <h2 class="wizard-card-title">Quel type de serveur ?</h2>
      <p class="wizard-card-sub">Choisis le moteur de ton serveur Minecraft. Tu pourras ajouter plugins et mods ensuite.</p>

      <div class="form-group">
        <label class="form-label">Type de serveur</label>
        <div class="type-grid">
          <label class="type-card" onclick="selectType('vanilla', this)">
            <input type="radio" name="server_type" value="vanilla">
            <div class="type-icon">🟢</div>
            <div class="type-name">Vanilla</div>
            <div class="type-desc">Officiel Mojang</div>
          </label>
          <label class="type-card selected" onclick="selectType('paper', this)">
            <input type="radio" name="server_type" value="paper" checked>
            <div class="type-icon">📄</div>
            <div class="type-name">Paper</div>
            <div class="type-desc">Plugins Bukkit</div>
          </label>
          <label class="type-card" onclick="selectType('spigot', this)">
            <input type="radio" name="server_type" value="spigot">
            <div class="type-icon">🔌</div>
            <div class="type-name">Spigot</div>
            <div class="type-desc">Plugins Bukkit</div>
          </label>
          <label class="type-card" onclick="selectType('forge', this)">
            <input type="radio" name="server_type" value="forge">
            <div class="type-icon">⚙️</div>
            <div class="type-name">Forge</div>
            <div class="type-desc">Mods Java</div>
          </label>
          <label class="type-card" onclick="selectType('fabric', this)">
            <input type="radio" name="server_type" value="fabric">
            <div class="type-icon">🧵</div>
            <div class="type-name">Fabric</div>
            <div class="type-desc">Mods légers</div>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Version Minecraft</label>
        <div id="versions-container">
          <div class="loading"><div class="spinner"></div> Chargement des versions…</div>
        </div>
        <input type="hidden" id="mc_version" value="">
      </div>

      <div class="form-group" id="loader-version-group" style="display:none;">
        <label class="form-label">Version du loader <span id="loader-label-name"></span></label>
        <div id="loader-versions-container">
          <div class="loading"><div class="spinner"></div> Chargement…</div>
        </div>
        <input type="hidden" id="loader_version" value="">
      </div>

      <div class="form-group">
        <label class="form-label">Nom du serveur</label>
        <input class="form-input" type="text" id="server_name" placeholder="Mon super serveur Minecraft" maxlength="100">
      </div>

      <div class="wizard-nav">
        <span></span>
        <button class="btn-wizard btn-wizard-primary" onclick="goStep(2)">Continuer →</button>
      </div>
    </div>
  </div>

  <!-- ── Étape 2 : Configuration ── -->
  <div id="step-2" style="display:none;">
    <div class="wizard-card">
      <h2 class="wizard-card-title">Configuration du serveur</h2>
      <p class="wizard-card-sub">Paramètres réseau, ressources et règles de jeu.</p>

      <div class="form-group">
        <label class="form-label">Description <span>(optionnel)</span></label>
        <textarea class="form-input" id="server_description" rows="2" placeholder="Description courte du serveur…" style="resize:vertical;"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">IP du serveur <span>(optionnel — à renseigner plus tard)</span></label>
          <input class="form-input" type="text" id="server_ip" placeholder="play.monserveur.fr">
        </div>
        <div class="form-group">
          <label class="form-label">Port</label>
          <input class="form-input" type="number" id="server_port" value="25565" min="1" max="65535">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">RAM allouée (Mo)</label>
          <select class="form-input" id="ram_mb">
            <option value="1024">1024 Mo — 1 Go</option>
            <option value="2048" selected>2048 Mo — 2 Go</option>
            <option value="3072">3072 Mo — 3 Go</option>
            <option value="4096">4096 Mo — 4 Go</option>
            <option value="6144">6144 Mo — 6 Go</option>
            <option value="8192">8192 Mo — 8 Go</option>
            <option value="12288">12288 Mo — 12 Go</option>
            <option value="16384">16384 Mo — 16 Go</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Joueurs max</label>
          <input class="form-input" type="number" id="max_players" value="20" min="1" max="1000">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Règles du jeu</label>
        <div class="config-grid">
          <div class="toggle-row">
            <div>
              <div class="toggle-label">Mode en ligne</div>
              <div class="toggle-sub">Vérifie les comptes Mojang authentiques</div>
            </div>
            <label class="toggle">
              <input type="checkbox" id="online_mode" checked>
              <div class="toggle-slider"></div>
            </label>
          </div>
          <div class="toggle-row">
            <div>
              <div class="toggle-label">PvP activé</div>
              <div class="toggle-sub">Permettre les combats entre joueurs</div>
            </div>
            <label class="toggle">
              <input type="checkbox" id="pvp" checked>
              <div class="toggle-slider"></div>
            </label>
          </div>
          <div class="toggle-row">
            <div>
              <div class="toggle-label">Whitelist</div>
              <div class="toggle-sub">Restreindre l'accès aux joueurs autorisés</div>
            </div>
            <label class="toggle">
              <input type="checkbox" id="whitelist">
              <div class="toggle-slider"></div>
            </label>
          </div>
          <div class="toggle-row">
            <div>
              <div class="toggle-label">Command blocks</div>
              <div class="toggle-sub">Activer les blocs de commande</div>
            </div>
            <label class="toggle">
              <input type="checkbox" id="cmd_blocks">
              <div class="toggle-slider"></div>
            </label>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Difficulté</label>
          <select class="form-input" id="difficulty">
            <option value="peaceful">Paisible</option>
            <option value="easy">Facile</option>
            <option value="normal" selected>Normal</option>
            <option value="hard">Difficile</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Mode de jeu</label>
          <select class="form-input" id="gamemode">
            <option value="survival" selected>Survie</option>
            <option value="creative">Créatif</option>
            <option value="adventure">Aventure</option>
            <option value="spectator">Spectateur</option>
          </select>
        </div>
      </div>

      <div class="wizard-nav">
        <button class="btn-wizard" onclick="goStep(1)">← Retour</button>
        <button class="btn-wizard btn-wizard-primary" onclick="goStep(3)">Récapitulatif →</button>
      </div>
    </div>
  </div>

  <!-- ── Étape 3 : Récapitulatif ── -->
  <div id="step-3" style="display:none;">
    <div class="wizard-card">
      <h2 class="wizard-card-title">✅ Récapitulatif</h2>
      <p class="wizard-card-sub">Vérifie les infos avant de créer ton serveur.</p>

      <div class="summary-grid" id="summary-content"><!-- rempli en JS --></div>

      <div id="create-error" class="error-msg" style="display:none;"></div>

      <div class="wizard-nav">
        <button class="btn-wizard" onclick="goStep(2)">← Modifier</button>
        <button class="btn-wizard btn-wizard-primary" id="btn-create" onclick="createServer()">
          🚀 Créer le serveur
        </button>
      </div>
    </div>
  </div>

</div><!-- /wizard -->

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const API  = '<?= e(server_cms_base()) ?>/api';

let state = {
  server_type: 'paper',
  mc_version: '',
  loader_version: '',
  server_name: '',
};

// ── Type selection ─────────────────────────────────────────────────────────
function selectType(type, el) {
  document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  state.server_type = type;
  loadVersions(type);
}

// ── Versions loading ───────────────────────────────────────────────────────
async function loadVersions(type) {
  const container = document.getElementById('versions-container');
  const loaderGroup = document.getElementById('loader-version-group');

  container.innerHTML = '<div class="loading"><div class="spinner"></div> Chargement…</div>';
  document.getElementById('mc_version').value = '';
  state.mc_version = '';
  loaderGroup.style.display = 'none';

  const res = await fetch(`${API}/mc_versions.php?type=${type}`);
  const data = await res.json();

  if (!data.ok) {
    container.innerHTML = '<div class="error-msg">Impossible de charger les versions.</div>';
    return;
  }

  let versions = [];

  if (type === 'fabric') {
    versions = data.data.mc_versions || [];
    // Charger aussi les loader versions Fabric
    const loaders = data.data.loader_versions || [];
    renderLoaderVersions(loaders, 'Fabric Loader');
  } else if (type === 'paper') {
    versions = data.data;
  } else if (type === 'spigot') {
    versions = data.data;
  } else if (type === 'forge') {
    versions = data.data;
  } else {
    versions = data.data.map(v => v.id || v);
  }

  renderVersions(versions, type);
}

function renderVersions(versions, type) {
  const container = document.getElementById('versions-container');
  if (!Array.isArray(versions) || versions.length === 0) {
    container.innerHTML = '<div class="error-msg">Aucune version disponible.</div>';
    return;
  }

  const html = '<div class="version-list">' +
    versions.map(v => {
      const label = typeof v === 'object' ? (v.id || v.version || JSON.stringify(v)) : v;
      return `<div class="version-chip" onclick="selectVersion('${label}', this)">${label}</div>`;
    }).join('') +
  '</div>';
  container.innerHTML = html;
}

function selectVersion(ver, el) {
  document.querySelectorAll('.version-chip').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  state.mc_version = ver;
  document.getElementById('mc_version').value = ver;

  // Pour Paper/Forge : charger les builds
  if (state.server_type === 'paper') {
    loadLoaderVersions(ver);
  } else if (state.server_type === 'forge') {
    loadForgeBuilds(ver);
  }
}

async function loadLoaderVersions(mcVersion) {
  const res = await fetch(`${API}/mc_versions.php?type=paper&mc_version=${mcVersion}`);
  const data = await res.json();
  if (data.ok && data.data.builds) {
    renderLoaderVersions(data.data.builds, 'Paper Build');
  }
}

async function loadForgeBuilds(mcVersion) {
  const res = await fetch(`${API}/mc_versions.php?type=forge&mc_version=${mcVersion}`);
  const data = await res.json();
  if (data.ok && data.data.builds) {
    renderLoaderVersions(data.data.builds, 'Forge');
  }
}

function renderLoaderVersions(builds, labelName) {
  const group = document.getElementById('loader-version-group');
  const container = document.getElementById('loader-versions-container');
  document.getElementById('loader-label-name').textContent = labelName;

  if (!builds || builds.length === 0) {
    group.style.display = 'none';
    return;
  }

  group.style.display = 'block';
  const html = '<div class="version-list loader-row">' +
    builds.map(b => {
      const label = String(b);
      return `<div class="version-chip" onclick="selectLoaderVersion('${label}', this)">${label}</div>`;
    }).join('') +
  '</div>';
  container.innerHTML = html;
}

function selectLoaderVersion(ver, el) {
  el.closest('.version-list').querySelectorAll('.version-chip').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  state.loader_version = ver;
  document.getElementById('loader_version').value = ver;
}

// ── Step navigation ────────────────────────────────────────────────────────
function goStep(n) {
  if (n === 2) {
    state.server_name = document.getElementById('server_name').value.trim();
    if (!state.mc_version) { alert('Sélectionne une version Minecraft.'); return; }
    if (!state.server_name) { alert('Donne un nom à ton serveur.'); return; }
  }
  if (n === 3) {
    renderSummary();
  }

  [1,2,3].forEach(i => {
    document.getElementById(`step-${i}`).style.display = i === n ? 'block' : 'none';
  });

  // Update step indicators
  [1,2,3].forEach(i => {
    const el = document.getElementById(`step-ind-${i}`);
    el.classList.remove('active','done');
    if (i < n) el.classList.add('done');
    if (i === n) el.classList.add('active');
  });
}

function renderSummary() {
  const typeNames = {vanilla:'Vanilla',paper:'Paper',spigot:'Spigot',forge:'Forge',fabric:'Fabric'};
  const items = [
    ['Nom', document.getElementById('server_name').value],
    ['Type', typeNames[state.server_type] || state.server_type],
    ['Version MC', state.mc_version],
    ['Loader', state.loader_version || '—'],
    ['IP', document.getElementById('server_ip').value || 'À définir'],
    ['Port', document.getElementById('server_port').value],
    ['RAM', document.getElementById('ram_mb').value + ' Mo'],
    ['Joueurs max', document.getElementById('max_players').value],
    ['Mode en ligne', document.getElementById('online_mode').checked ? 'Oui' : 'Non'],
    ['PvP', document.getElementById('pvp').checked ? 'Activé' : 'Désactivé'],
    ['Whitelist', document.getElementById('whitelist').checked ? 'Activée' : 'Désactivée'],
    ['Difficulté', document.getElementById('difficulty').value],
  ];

  document.getElementById('summary-content').innerHTML = items.map(([k, v]) => `
    <div class="summary-item">
      <div class="summary-key">${k}</div>
      <div class="summary-val">${v}</div>
    </div>
  `).join('');
}

// ── Create server ──────────────────────────────────────────────────────────
async function createServer() {
  const btn = document.getElementById('btn-create');
  const errEl = document.getElementById('create-error');
  btn.disabled = true;
  btn.textContent = '⏳ Création…';
  errEl.style.display = 'none';

  const payload = {
    _csrf: CSRF,
    name: document.getElementById('server_name').value.trim(),
    description: document.getElementById('server_description').value.trim(),
    server_type: state.server_type,
    mc_version: state.mc_version,
    loader_version: state.loader_version,
    server_ip: document.getElementById('server_ip').value.trim(),
    server_port: parseInt(document.getElementById('server_port').value) || 25565,
    ram_mb: parseInt(document.getElementById('ram_mb').value) || 2048,
    server_config: {
      'max-players': parseInt(document.getElementById('max_players').value) || 20,
      'online-mode': document.getElementById('online_mode').checked,
      'pvp': document.getElementById('pvp').checked,
      'white-list': document.getElementById('whitelist').checked,
      'enforce-whitelist': document.getElementById('whitelist').checked,
      'enable-command-block': document.getElementById('cmd_blocks').checked,
      'difficulty': document.getElementById('difficulty').value,
      'gamemode': document.getElementById('gamemode').value,
    },
  };

  try {
    const res = await fetch(`${API}/server.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': CSRF },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (data.ok && data.server) {
      window.location.href = `<?= e(server_cms_base()) ?>/dashboard/manage.php?uuid=${data.server.uuid}`;
    } else {
      errEl.textContent = '❌ ' + (data.error || 'Erreur inconnue');
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = '🚀 Créer le serveur';
    }
  } catch (e) {
    errEl.textContent = '❌ Erreur réseau : ' + e.message;
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.textContent = '🚀 Créer le serveur';
  }
}

// ── Init : charger les versions Paper par défaut ───────────────────────────
loadVersions('paper');
</script>
</body>
</html>
