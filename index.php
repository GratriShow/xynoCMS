<?php
declare(strict_types=1);

// Routeur minimal — redirige les requêtes vers les vrais fichiers PHP
$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$requestPath = $requestPath === '' ? '/' : $requestPath;

if ($requestPath !== '/' && $requestPath !== '/index.php' && str_ends_with($requestPath, '.php')) {
  $publicRoot  = realpath(__DIR__) ?: __DIR__;
  $candidate   = __DIR__ . $requestPath;
  $candidateReal = realpath($candidate);
  if ($candidateReal !== false
    && str_starts_with($candidateReal, $publicRoot)
    && is_file($candidateReal)
    && is_readable($candidateReal)
  ) {
    require $candidateReal;
    exit;
  }
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>XynoWeb — L'écosystème gaming tout-en-un pour Minecraft</title>
  <meta name="description" content="Launcher, serveur hébergé, site web pour ta communauté Minecraft — tout depuis un seul dashboard. Déjà utilisé par des dizaines de communautés francophones."/>
  <link rel="canonical" href="https://xynoweb.fr/"/>
  <meta property="og:type"        content="website"/>
  <meta property="og:url"         content="https://xynoweb.fr/"/>
  <meta property="og:title"       content="XynoWeb — L'écosystème gaming tout-en-un pour Minecraft"/>
  <meta property="og:description" content="Launcher personnalisé, serveur hébergé, site communauté — tout sous une seule enseigne."/>
  <meta property="og:image"       content="https://xynoweb.fr/assets/social/og-default.svg"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
  /* ═══════════════════════════════════════════════════════
     XynoWeb Homepage — Design System Extension
  ═══════════════════════════════════════════════════════ */

  /* ── Overrides nav ── */
  .nav { border-bottom: 1px solid var(--border-0); }

  /* ── Hero ── */
  .hero {
    position: relative;
    min-height: 92vh;
    display: flex;
    align-items: center;
    overflow: hidden;
    padding: 100px 0 80px;
  }
  .hero-bg {
    position: absolute; inset: 0;
    background:
      radial-gradient(ellipse 80% 60% at 60% 20%, rgba(124,92,255,.18) 0%, transparent 60%),
      radial-gradient(ellipse 60% 40% at 10% 80%, rgba(59,130,246,.12) 0%, transparent 55%),
      var(--bg-0);
    z-index: 0;
  }
  .hero-grid-bg {
    position: absolute; inset: 0;
    background-image:
      linear-gradient(rgba(124,92,255,.04) 1px, transparent 1px),
      linear-gradient(90deg, rgba(124,92,255,.04) 1px, transparent 1px);
    background-size: 48px 48px;
    z-index: 0;
  }
  .hero-content { position: relative; z-index: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; }
  @media (max-width: 860px) { .hero-content { grid-template-columns: 1fr; } .hero-visual { display: none; } }

  .hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--accent-soft); border: 1px solid var(--accent-border);
    border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 600;
    color: var(--accent-light); margin-bottom: 20px;
  }
  .hero-badge .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); animation: blink 2s infinite; }
  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

  .hero h1 {
    font-size: clamp(36px, 5vw, 60px);
    font-weight: 900;
    line-height: 1.08;
    letter-spacing: -.03em;
    margin: 0 0 20px;
  }
  .hero h1 .grad {
    background: var(--grad-text);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .hero-sub {
    font-size: 18px; color: var(--muted); line-height: 1.7; margin: 0 0 36px; max-width: 520px;
  }
  .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
  .btn-hero-primary {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--grad-primary); color: #fff; font-weight: 700; font-size: 15px;
    padding: 14px 28px; border-radius: var(--radius-md); border: none; cursor: pointer;
    box-shadow: var(--shadow-brand); text-decoration: none; transition: .2s;
  }
  .btn-hero-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 36px rgba(124,92,255,.55); }
  .btn-hero-secondary {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--surface-2); color: var(--text); font-weight: 600; font-size: 15px;
    padding: 14px 24px; border-radius: var(--radius-md);
    border: 1px solid var(--border-2); text-decoration: none; transition: .2s;
  }
  .btn-hero-secondary:hover { background: var(--surface-3); border-color: var(--border-3); }

  .hero-stats {
    display: flex; gap: 28px; margin-top: 40px; flex-wrap: wrap;
  }
  .hero-stat { display: flex; flex-direction: column; }
  .hero-stat .num { font-size: 26px; font-weight: 800; color: var(--text); }
  .hero-stat .lbl { font-size: 12px; color: var(--muted); margin-top: 2px; }

  /* Hero visuel — mockup dashboard */
  .hero-visual {
    position: relative;
  }
  .hero-mockup {
    width: 100%; border-radius: var(--radius-xl);
    border: 1px solid var(--border-1);
    box-shadow: 0 32px 80px rgba(0,0,0,.7), 0 0 0 1px rgba(124,92,255,.12);
    overflow: hidden;
  }
  .hero-mockup img { width: 100%; display: block; }
  .hero-float-card {
    position: absolute; bottom: -16px; left: -28px;
    background: var(--surface); border: 1px solid var(--border-2);
    border-radius: var(--radius-md); padding: 12px 16px;
    box-shadow: var(--shadow-md); min-width: 160px;
  }
  .hero-float-card .label { font-size: 11px; color: var(--muted); margin-bottom: 4px; }
  .hero-float-card .value { font-size: 18px; font-weight: 700; color: var(--success); }

  /* ── Section générique ── */
  .section { padding: 96px 0; }
  .section-sm { padding: 64px 0; }
  .section-label {
    display: inline-block; font-size: 12px; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: var(--accent-light);
    background: var(--accent-soft); border: 1px solid var(--accent-border);
    padding: 4px 12px; border-radius: 999px; margin-bottom: 14px;
  }
  .section-title {
    font-size: clamp(28px, 3.5vw, 44px); font-weight: 800; letter-spacing: -.025em; line-height: 1.15;
    margin: 0 0 14px; color: var(--text);
  }
  .section-sub { font-size: 17px; color: var(--muted); line-height: 1.7; max-width: 560px; margin: 0 auto 50px; }
  .text-center { text-align: center; }

  /* ── Produits ── */
  .products-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
  }
  @media (max-width: 900px) { .products-grid { grid-template-columns: 1fr; } }

  .product-card {
    background: var(--surface); border: 1px solid var(--border-1);
    border-radius: var(--radius-xl); overflow: hidden;
    transition: .25s; position: relative;
    display: flex; flex-direction: column;
  }
  .product-card:hover { border-color: var(--border-2); transform: translateY(-4px); box-shadow: var(--shadow-lg); }
  .product-card.featured { border-color: var(--accent-border); box-shadow: 0 0 0 1px var(--accent-border); }

  .product-img {
    width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block;
  }
  .product-img-placeholder {
    width: 100%; aspect-ratio: 16/9;
    display: flex; align-items: center; justify-content: center;
    font-size: 48px;
  }

  .product-body { padding: 24px; flex: 1; display: flex; flex-direction: column; }
  .product-tag {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    padding: 3px 10px; border-radius: 999px; margin-bottom: 14px;
  }
  .tag-live    { background: rgba(0,214,143,.12); color: #00d68f; border: 1px solid rgba(0,214,143,.25); }
  .tag-mvp     { background: rgba(124,92,255,.12); color: #b8a4ff; border: 1px solid rgba(124,92,255,.25); }
  .tag-soon    { background: rgba(255,190,0,.10); color: #ffbe00; border: 1px solid rgba(255,190,0,.22); }

  .product-body h3 { font-size: 20px; font-weight: 700; margin: 0 0 8px; }
  .product-body p  { font-size: 14px; color: var(--muted); line-height: 1.65; margin: 0 0 18px; flex: 1; }

  .product-features { list-style: none; padding: 0; margin: 0 0 22px; display: flex; flex-direction: column; gap: 7px; }
  .product-features li { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; }
  .product-features li::before { content: ''; width: 16px; height: 16px; border-radius: 50%;
    background: var(--accent-soft); border: 1px solid var(--accent-border); flex-shrink: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 16 16' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M4 8l3 3 5-5' stroke='%23b8a4ff' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-size: contain; }

  .btn-product {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 10px 20px; border-radius: var(--radius-md); font-size: 14px; font-weight: 600;
    text-decoration: none; transition: .2s; border: 1px solid var(--border-2);
    background: var(--surface-2); color: var(--text);
  }
  .btn-product:hover { background: var(--surface-3); border-color: var(--accent-border); color: var(--accent-light); }
  .btn-product.primary { background: var(--accent); border-color: var(--accent); color: #fff; box-shadow: var(--shadow-brand); }
  .btn-product.primary:hover { background: var(--accent-hover); }
  .btn-product.disabled { opacity: .45; pointer-events: none; }

  /* ── Stats band ── */
  .stats-band {
    background: var(--surface); border-top: 1px solid var(--border-1); border-bottom: 1px solid var(--border-1);
    padding: 40px 0;
  }
  .stats-inner { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
  .stat-item { text-align: center; flex: 1; min-width: 120px; }
  .stat-item .num { font-size: 36px; font-weight: 900; color: var(--text); letter-spacing: -.02em; }
  .stat-item .num .unit { font-size: 20px; color: var(--accent-light); }
  .stat-item .lbl { font-size: 13px; color: var(--muted); margin-top: 4px; }
  .stat-divider { width: 1px; height: 60px; background: var(--border-1); flex-shrink: 0; }
  @media (max-width: 640px) { .stat-divider { display: none; } }

  /* ── Features ── */
  .features-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
  }
  @media (max-width: 860px) { .features-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 540px) { .features-grid { grid-template-columns: 1fr; } }

  .feat-card {
    background: var(--surface); border: 1px solid var(--border-1);
    border-radius: var(--radius-lg); padding: 24px; transition: .2s;
  }
  .feat-card:hover { border-color: var(--border-2); }
  .feat-icon-wrap {
    width: 44px; height: 44px; border-radius: var(--radius-md);
    display: grid; place-items: center; margin-bottom: 16px;
    font-size: 20px;
  }
  .feat-card h4 { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
  .feat-card p  { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 0; }

  /* ── Pricing preview ── */
  .plans-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
  }
  @media (max-width: 960px) { .plans-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 560px) { .plans-grid { grid-template-columns: 1fr; } }

  .plan-card {
    background: var(--surface); border: 1px solid var(--border-1);
    border-radius: var(--radius-lg); padding: 24px; transition: .25s; position: relative;
  }
  .plan-card:hover { border-color: var(--border-2); transform: translateY(-3px); }
  .plan-card.featured-plan {
    border-color: var(--accent-border);
    background: linear-gradient(180deg, rgba(124,92,255,.06) 0%, var(--surface) 100%);
  }
  .plan-badge-top {
    position: absolute; top: -11px; left: 50%; transform: translateX(-50%);
    background: var(--accent); color: #fff; font-size: 11px; font-weight: 700;
    padding: 3px 12px; border-radius: 999px; white-space: nowrap;
  }
  .plan-name { font-size: 18px; font-weight: 800; margin: 0 0 6px; }
  .plan-price { font-size: 34px; font-weight: 900; letter-spacing: -.02em; margin: 12px 0 4px; }
  .plan-price .currency { font-size: 18px; vertical-align: top; margin-top: 6px; display: inline-block; }
  .plan-price .period { font-size: 13px; color: var(--muted); font-weight: 400; }
  .plan-desc { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
  .plan-specs { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
  .plan-spec { font-size: 12.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
  .plan-spec::before { content: '✓'; color: var(--accent-light); font-weight: 700; }

  /* ── Comparaison ── */
  .compare-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .compare-table thead th {
    background: var(--surface); padding: 14px 16px; text-align: center;
    font-weight: 700; border-bottom: 1px solid var(--border-1); color: var(--text);
  }
  .compare-table thead th:first-child { text-align: left; }
  .compare-table tbody tr:nth-child(even) { background: var(--surface); }
  .compare-table tbody td { padding: 11px 16px; border-bottom: 1px solid var(--border-0); color: var(--muted); }
  .compare-table tbody td:first-child { color: var(--text); font-weight: 500; }
  .compare-table tbody td.center { text-align: center; }
  .compare-table .chk { color: var(--accent-light); font-weight: 700; }
  .compare-table .cross { color: var(--muted-2); }
  .compare-table .xyno-col { background: rgba(124,92,255,.06); color: var(--accent-light); font-weight: 700; }

  /* ── Workflow ── */
  .workflow { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; position: relative; }
  @media (max-width: 760px) { .workflow { grid-template-columns: 1fr; gap: 16px; } .workflow-line { display: none; } }
  .workflow-line { position: absolute; top: 28px; left: 12.5%; right: 12.5%; height: 1px;
                   background: linear-gradient(90deg, transparent, var(--border-2), var(--border-2), transparent);
                   z-index: 0; }
  .workflow-step { text-align: center; position: relative; z-index: 1; padding: 0 12px; }
  .workflow-step .step-num {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--surface); border: 2px solid var(--accent-border);
    display: grid; place-items: center; margin: 0 auto 16px;
    font-size: 18px; font-weight: 800; color: var(--accent-light);
    box-shadow: 0 0 0 6px var(--accent-soft);
  }
  .workflow-step h4 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
  .workflow-step p  { font-size: 12.5px; color: var(--muted); line-height: 1.55; margin: 0; }

  /* ── Image sections ── */
  .img-section { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; }
  @media (max-width: 860px) { .img-section { grid-template-columns: 1fr; } }
  .img-section img, .img-section .img-placeholder {
    width: 100%; border-radius: var(--radius-xl);
    border: 1px solid var(--border-1); box-shadow: var(--shadow-lg);
  }
  .img-section .img-placeholder {
    aspect-ratio: 16/10; object-fit: cover; display: block;
  }
  .img-section .text-side h2 { font-size: clamp(24px, 3vw, 36px); font-weight: 800; letter-spacing: -.025em; margin: 0 0 14px; }
  .img-section .text-side p  { font-size: 15px; color: var(--muted); line-height: 1.75; margin: 0 0 20px; }
  .check-list { list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 10px; }
  .check-list li { font-size: 14px; color: var(--muted); display: flex; gap: 10px; align-items: flex-start; }
  .check-list li .ck { color: var(--accent-light); font-weight: 700; flex-shrink: 0; margin-top: 1px; }

  /* ── CTA final ── */
  .cta-band {
    background: linear-gradient(135deg, rgba(124,92,255,.15) 0%, rgba(59,130,246,.08) 100%);
    border-top: 1px solid var(--accent-border); border-bottom: 1px solid var(--border-1);
    padding: 80px 0; text-align: center;
  }
  .cta-band h2 { font-size: clamp(28px, 4vw, 48px); font-weight: 900; letter-spacing: -.025em; margin: 0 0 14px; }
  .cta-band p  { font-size: 18px; color: var(--muted); margin: 0 0 36px; }

  /* ── Footer ── */
  .footer {
    background: var(--bg-0); border-top: 1px solid var(--border-0);
    padding: 48px 0 32px;
  }
  .footer-grid {
    display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 32px; margin-bottom: 40px;
  }
  @media (max-width: 760px) { .footer-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 480px) { .footer-grid { grid-template-columns: 1fr; } }
  .footer-brand .logo { font-size: 22px; font-weight: 800; color: var(--text); text-decoration: none; letter-spacing: -.02em; }
  .footer-brand .logo span { color: var(--accent); }
  .footer-brand p { font-size: 13px; color: var(--muted); line-height: 1.65; margin: 12px 0 0; max-width: 260px; }
  .footer-col h4 { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
                   color: var(--muted); margin: 0 0 14px; }
  .footer-col a  { display: block; font-size: 13px; color: var(--muted); text-decoration: none;
                   margin-bottom: 8px; transition: .15s; }
  .footer-col a:hover { color: var(--text); }
  .footer-bottom { border-top: 1px solid var(--border-0); padding-top: 24px;
                   display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
  .footer-bottom p { font-size: 12px; color: var(--muted-2); margin: 0; }
  </style>
</head>
<body>

<!-- ── NAV ─────────────────────────────────────────────────────────────── -->
<nav class="nav">
  <div class="container" style="display:flex;align-items:center;justify-content:space-between;height:var(--nav-h);">
    <a href="/" style="font-size:20px;font-weight:800;color:var(--text);text-decoration:none;letter-spacing:-.02em;">
      Xyno<span style="color:var(--accent);">Web</span>
    </a>
    <div style="display:flex;align-items:center;gap:6px;">
      <a href="/pricing.php"        class="nav-link">Tarifs</a>
      <a href="/server-cms/pricing.php" class="nav-link">Serveurs</a>
      <a href="/self-hosting.php"   class="nav-link">Self-hosting</a>
      <a href="/login.php"          class="btn btn-ghost" style="margin-left:8px;">Connexion</a>
      <a href="/register.php"       class="btn btn-primary">Démarrer</a>
    </div>
  </div>
</nav>

<!-- ── HERO ───────────────────────────────────────────────────────────── -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid-bg"></div>
  <div class="container">
    <div class="hero-content">
      <div>
        <div class="hero-badge">
          <span class="dot"></span>
          En production — communautés actives
        </div>
        <h1>
          L'écosystème gaming<br/>
          <span class="grad">tout-en-un</span><br/>
          pour Minecraft
        </h1>
        <p class="hero-sub">
          Launcher personnalisé, serveur hébergé, site communauté —
          tout depuis un seul dashboard. Zéro technique, 100% à ton image.
        </p>
        <div class="hero-actions">
          <a href="/register.php" class="btn-hero-primary">
            Commencer gratuitement
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
          <a href="#produits" class="btn-hero-secondary">
            Voir les offres
          </a>
        </div>
        <div class="hero-stats">
          <div class="hero-stat">
            <span class="num">3</span>
            <span class="lbl">produits intégrés</span>
          </div>
          <div class="hero-stat">
            <span class="num">100%</span>
            <span class="lbl">interface française</span>
          </div>
          <div class="hero-stat">
            <span class="num">1</span>
            <span class="lbl">seul dashboard</span>
          </div>
        </div>
      </div>
      <div class="hero-visual">
        <div class="hero-mockup">
          <img
            src="https://images.unsplash.com/photo-1607799279861-4dd421887fb3?w=900&auto=format&fit=crop&q=80"
            alt="Dashboard XynoWeb"
            loading="lazy"
          />
        </div>
        <div class="hero-float-card">
          <div class="label">Serveurs actifs</div>
          <div class="value">▲ En ligne</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── STATS BAND ─────────────────────────────────────────────────────── -->
<div class="stats-band">
  <div class="container">
    <div class="stats-inner">
      <div class="stat-item">
        <div class="num">6<span class="unit">+</span></div>
        <div class="lbl">Thèmes launcher</div>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <div class="num">1.21<span class="unit">+</span></div>
        <div class="lbl">Versions Minecraft</div>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <div class="num">4</div>
        <div class="lbl">Plans d'hébergement</div>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <div class="num">100%</div>
        <div class="lbl">Français</div>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-item">
        <div class="num">0€</div>
        <div class="lbl">Pour commencer</div>
      </div>
    </div>
  </div>
</div>

<!-- ── PRODUITS ───────────────────────────────────────────────────────── -->
<section class="section" id="produits">
  <div class="container">
    <div class="text-center" style="margin-bottom:48px;">
      <span class="section-label">L'écosystème</span>
      <h2 class="section-title">Trois produits,<br/>un seul compte</h2>
      <p class="section-sub">
        XynoWeb réunit tous les outils d'une communauté Minecraft sous une même enseigne.
        Ton launcher, ton serveur et ton site se parlent automatiquement.
      </p>
    </div>

    <div class="products-grid">

      <!-- XynoLauncher -->
      <div class="product-card featured">
        <img class="product-img"
          src="https://images.unsplash.com/photo-1542751371-adc38448a05e?w=700&auto=format&fit=crop&q=80"
          alt="XynoLauncher" loading="lazy"/>
        <div class="product-body">
          <span class="product-tag tag-live">● En production</span>
          <h3>XynoLauncher</h3>
          <p>Crée et distribue un launcher Minecraft entièrement personnalisé sans écrire une seule ligne de code. Branding, modpacks, auto-update et auth Microsoft intégrés.</p>
          <ul class="product-features">
            <li>6 thèmes premium inclus</li>
            <li>Auth Microsoft + mode offline</li>
            <li>Modpacks & auto-update</li>
            <li>Build auto win/mac/linux (CI)</li>
          </ul>
          <a href="/pricing.php" class="btn-product primary">Voir les offres →</a>
        </div>
      </div>

      <!-- XynoServer -->
      <div class="product-card">
        <img class="product-img"
          src="https://images.unsplash.com/photo-1558494949-ef010cbdcc31?w=700&auto=format&fit=crop&q=80"
          alt="XynoServer" loading="lazy"/>
        <div class="product-body">
          <span class="product-tag tag-mvp">◆ MVP livré</span>
          <h3>XynoServer</h3>
          <p>Lance ton serveur Minecraft en quelques minutes. Choisis ta version, installe tes plugins en 1 clic, gère ta whitelist et suis les performances depuis ton navigateur.</p>
          <ul class="product-features">
            <li>Paper, Forge, Fabric, Vanilla</li>
            <li>Plugins Modrinth 1 clic</li>
            <li>Console live + monitoring RAM/TPS</li>
            <li>Backups automatiques</li>
          </ul>
          <a href="/server-cms/pricing.php" class="btn-product">Voir les plans →</a>
        </div>
      </div>

      <!-- XynoSite -->
      <div class="product-card">
        <img class="product-img"
          src="https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=700&auto=format&fit=crop&q=80"
          alt="XynoSite" loading="lazy"/>
        <div class="product-body">
          <span class="product-tag tag-soon">○ Bientôt</span>
          <h3>XynoSite</h3>
          <p>Crée le site web de ta communauté gaming, connecté nativement à ton launcher et ton serveur. Stats de joueurs, actualités, vote, classements — tout en un.</p>
          <ul class="product-features">
            <li>Connecté au launcher & serveur</li>
            <li>Stats joueurs en temps réel</li>
            <li>Actualités & vote automatisé</li>
            <li>Thèmes gaming premium</li>
          </ul>
          <a href="#" class="btn-product disabled">Bientôt disponible</a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ── LAUNCHER SECTION ────────────────────────────────────────────────── -->
<section class="section" style="background:var(--surface); border-top:1px solid var(--border-0); border-bottom:1px solid var(--border-0);">
  <div class="container">
    <div class="img-section">
      <img
        src="https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=800&auto=format&fit=crop&q=80"
        alt="XynoLauncher en action"
        loading="lazy"
        style="aspect-ratio:16/10;object-fit:cover;"
      />
      <div class="text-side">
        <span class="section-label">XynoLauncher</span>
        <h2>Ton launcher,<br/>à ton image</h2>
        <p>
          Plus besoin d'être développeur. Tu choisis un thème, tu personnalises les couleurs et le logo,
          tu configures tes modpacks — XynoWeb compile et distribue automatiquement l'installateur
          pour Windows, macOS et Linux via GitHub Actions.
        </p>
        <ul class="check-list">
          <li><span class="ck">✓</span> <span>Authentification Microsoft (compte premium) et mode offline intégré</span></li>
          <li><span class="ck">✓</span> <span>Mise à jour automatique du launcher en arrière-plan</span></li>
          <li><span class="ck">✓</span> <span>Lié à ton serveur XynoWeb — tes joueurs rejoignent en 1 clic</span></li>
          <li><span class="ck">✓</span> <span>Dashboard d'administration pour gérer fichiers, versions et extensions</span></li>
        </ul>
        <a href="/pricing.php" class="btn-hero-primary" style="display:inline-flex;">Voir les offres XynoLauncher →</a>
      </div>
    </div>
  </div>
</section>

<!-- ── SERVER SECTION ─────────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="img-section" style="direction:rtl;">
      <img
        src="https://images.unsplash.com/photo-1544197150-b99a580bb7a8?w=800&auto=format&fit=crop&q=80"
        alt="XynoServer infrastructure"
        loading="lazy"
        style="aspect-ratio:16/10;object-fit:cover;direction:ltr;"
      />
      <div class="text-side" style="direction:ltr;">
        <span class="section-label">XynoServer</span>
        <h2>Ton serveur,<br/>prêt en 3 minutes</h2>
        <p>
          Choisis ton type de serveur, ta version Minecraft, installe tes plugins depuis Modrinth
          en 1 clic et configure tout depuis un dashboard web. Console live, monitoring RAM/TPS,
          backups automatiques — zéro SSH, zéro terminal.
        </p>
        <ul class="check-list">
          <li><span class="ck">✓</span> <span>Vanilla, Paper, Spigot, Forge, Fabric — toutes les versions</span></li>
          <li><span class="ck">✓</span> <span>Console en temps réel + envoi de commandes RCON depuis le navigateur</span></li>
          <li><span class="ck">✓</span> <span>Whitelist avec vrais UUID Mojang — compatible premium et offline</span></li>
          <li><span class="ck">✓</span> <span>Graphiques RAM, CPU, TPS et joueurs connectés en direct</span></li>
        </ul>
        <a href="/server-cms/pricing.php" class="btn-hero-primary" style="display:inline-flex;">Voir les plans serveur →</a>
      </div>
    </div>
  </div>
</section>

<!-- ── FONCTIONNALITÉS ─────────────────────────────────────────────────── -->
<section class="section" style="background:var(--surface); border-top:1px solid var(--border-0); border-bottom:1px solid var(--border-0);">
  <div class="container">
    <div class="text-center" style="margin-bottom:48px;">
      <span class="section-label">Fonctionnalités</span>
      <h2 class="section-title">Tout ce qu'il faut,<br/>rien de superflu</h2>
    </div>
    <div class="features-grid">
      <?php
      $feats = [
        ['🎨','var(--accent-soft)','Dashboard unifié','Un seul tableau de bord pour gérer ton launcher, ton serveur et ton site. Un seul compte, une seule API.'],
        ['💳','rgba(0,214,143,.1)','Billing intégré','Stripe intégré nativement — abonnements, paiements ponctuels, factures automatiques. Pas de dev requis.'],
        ['🔄','rgba(59,130,246,.1)','Auto-update','Le launcher se met à jour automatiquement en arrière-plan pour tous tes joueurs, sans qu\'ils aient à rien faire.'],
        ['🧩','rgba(251,191,36,.1)','Plugins 1 clic','Recherche et installe n\'importe quel plugin Modrinth directement depuis le dashboard, filtré par version MC.'],
        ['🔐','rgba(124,92,255,.1)','Auth flexible','Microsoft (compte premium), XynoWeb offline ou mode local pur. Chaque communauté choisit son mode.'],
        ['📊','rgba(0,214,143,.1)','Monitoring live','RAM, CPU, TPS, joueurs connectés — tout en temps réel avec des graphiques directement dans le dashboard.'],
        ['💾','rgba(251,191,36,.1)','Backups auto','Sauvegardes planifiées selon ton plan. Restauration en 1 clic depuis n\'importe quel snapshot.'],
        ['🌐','rgba(59,130,246,.1)','Interface FR','La seule plateforme tout-en-un entièrement en français, pensée pour les communautés francophones.'],
        ['🚀','rgba(124,92,255,.1)','CI/CD inclus','Build automatique de ton launcher pour Windows, macOS et Linux via GitHub Actions. Zéro configuration.'],
      ];
      foreach ($feats as [$icon, $bg, $title, $desc]):
      ?>
      <div class="feat-card">
        <div class="feat-icon-wrap" style="background:<?= $bg ?>;"><?= $icon ?></div>
        <h4><?= $title ?></h4>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── COMMENT ÇA MARCHE ──────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="text-center" style="margin-bottom:56px;">
      <span class="section-label">Démarrage</span>
      <h2 class="section-title">Opérationnel en moins d'une heure</h2>
    </div>
    <div class="workflow" style="position:relative;">
      <div class="workflow-line"></div>
      <?php
      $steps = [
        ['01','Crée ton compte','Inscription gratuite en 30 secondes. Aucune carte bleue requise pour commencer.'],
        ['02','Configure ton launcher','Choisis ton thème, personnalise, ajoute tes serveurs. Le dashboard guide chaque étape.'],
        ['03','Lance ton serveur','Sélectionne ton plan, configure ta version Minecraft et tes plugins. Le serveur démarre automatiquement.'],
        ['04','Invite tes joueurs','Partage le lien de téléchargement du launcher. Tes joueurs jouent en quelques clics.'],
      ];
      foreach ($steps as [$num, $title, $desc]):
      ?>
      <div class="workflow-step">
        <div class="step-num"><?= $num ?></div>
        <h4><?= $title ?></h4>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── PRICING PREVIEW ────────────────────────────────────────────────── -->
<section class="section" style="background:var(--surface);border-top:1px solid var(--border-0);border-bottom:1px solid var(--border-0);">
  <div class="container">
    <div class="text-center" style="margin-bottom:48px;">
      <span class="section-label">XynoServer — Hébergement</span>
      <h2 class="section-title">Des plans pour toutes les communautés</h2>
      <p class="section-sub">Facturation mensuelle, résiliable à tout moment. Pas d'engagement annuel obligatoire.</p>
    </div>
    <div class="plans-grid">
      <?php
      $plans = [
        ['Spark','🟢','2 Go RAM','1 vCPU','10 Go SSD','10 joueurs','1 backup/semaine', false, null],
        ['Core','🔵','4 Go RAM','2 vCPU','25 Go SSD','20 joueurs','2 backups/semaine', false, null],
        ['Pro','🟣','8 Go RAM','2 vCPU','50 Go NVMe','50 joueurs','Quotidien', true, 'Populaire'],
        ['Max','🔴','16 Go RAM','4 vCPU','100 Go NVMe','100 joueurs','7/jour', false, null],
      ];
      foreach ($plans as [$name, $emoji, $ram, $cpu, $disk, $players, $backups, $featured, $badge]):
      ?>
      <div class="plan-card <?= $featured ? 'featured-plan' : '' ?>">
        <?php if ($badge): ?><div class="plan-badge-top"><?= $badge ?></div><?php endif; ?>
        <div class="plan-name"><?= $emoji ?> <?= $name ?></div>
        <div class="plan-price"><span class="currency">€</span>?? <span class="period">/mois</span></div>
        <div class="plan-desc">Prix affiché après sélection du plan complet.</div>
        <div class="plan-specs">
          <div class="plan-spec"><?= $ram ?></div>
          <div class="plan-spec"><?= $cpu ?></div>
          <div class="plan-spec"><?= $disk ?></div>
          <div class="plan-spec"><?= $players ?></div>
          <div class="plan-spec">Backups : <?= $backups ?></div>
        </div>
        <a href="/server-cms/pricing.php" class="btn-product <?= $featured ? 'primary' : '' ?>">
          Voir ce plan →
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <p style="text-align:center;margin-top:24px;font-size:13px;color:var(--muted);">
      Prix complets, bundles et réductions sur
      <a href="/server-cms/pricing.php" style="color:var(--accent-light);">la page serveurs</a>
      et <a href="/pricing.php" style="color:var(--accent-light);">la page launcher</a>.
    </p>
  </div>
</section>

<!-- ── COMPARAISON ────────────────────────────────────────────────────── -->
<section class="section">
  <div class="container">
    <div class="text-center" style="margin-bottom:48px;">
      <span class="section-label">Comparaison</span>
      <h2 class="section-title">XynoWeb vs les alternatives</h2>
    </div>
    <div style="overflow-x:auto;">
    <table class="compare-table">
      <thead>
        <tr>
          <th style="width:38%;">Fonctionnalité</th>
          <th class="xyno-col">XynoWeb</th>
          <th>Aternos</th>
          <th>Bisect</th>
          <th>Apex</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = [
          ['Launcher Minecraft connecté',      '✓','✗','✗','✗'],
          ['Dashboard unifié launcher+serveur','✓','✗','✗','✗'],
          ['Site communauté intégré',          '✓','✗','✗','✗'],
          ['Interface 100% française',         '✓','Partiel','✗','✗'],
          ['Plugins Modrinth 1 clic',          '✓','✗','✓','✓'],
          ['Console live navigateur',          '✓','✗','✓','✓'],
          ['Whitelist UUID Mojang',            '✓','✓','✓','✓'],
          ['Auth Microsoft native',            '✓','✗','✗','✗'],
          ['Mode offline/crack',               '✓','✓','✗','✗'],
          ['Billing Stripe intégré',           '✓','✗','✓','✓'],
          ['Panel admin SaaS',                 '✓','—','—','—'],
        ];
        foreach ($rows as $row):
          $feat = $row[0]; $vals = array_slice($row, 1);
        ?>
        <tr>
          <td><?= $feat ?></td>
          <?php foreach ($vals as $i => $v): ?>
          <td class="center <?= $i===0 ? 'xyno-col' : '' ?>">
            <span class="<?= $v==='✓' ? 'chk' : ($v==='✗' ? 'cross' : '') ?>"><?= $v ?></span>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</section>

<!-- ── CTA FINAL ──────────────────────────────────────────────────────── -->
<section class="cta-band">
  <div class="container">
    <h2>Prêt à lancer<br/>ta communauté ?</h2>
    <p>Inscription gratuite, aucune carte bleue requise. Tu es opérationnel en moins d'une heure.</p>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
      <a href="/register.php"           class="btn-hero-primary">Créer mon compte gratuit →</a>
      <a href="/server-cms/pricing.php" class="btn-hero-secondary">Voir les serveurs</a>
    </div>
  </div>
</section>

<!-- ── FOOTER ─────────────────────────────────────────────────────────── -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a href="/" class="logo">Xyno<span>Web</span></a>
        <p>L'écosystème gaming tout-en-un pour les communautés Minecraft francophones. Launcher, serveur, site — un seul dashboard.</p>
      </div>
      <div class="footer-col">
        <h4>Produits</h4>
        <a href="/pricing.php">XynoLauncher</a>
        <a href="/server-cms/pricing.php">XynoServer</a>
        <a href="#">XynoSite (bientôt)</a>
        <a href="#">Marketplace (bientôt)</a>
      </div>
      <div class="footer-col">
        <h4>Ressources</h4>
        <a href="/self-hosting.php">Self-hosting</a>
        <a href="/pricing.php">Tarifs launcher</a>
        <a href="/server-cms/pricing.php">Tarifs serveurs</a>
      </div>
      <div class="footer-col">
        <h4>Légal</h4>
        <a href="/mentions-legales.php">Mentions légales</a>
        <a href="/politique-confidentialite.php">Confidentialité</a>
        <a href="/politique-cookies.php">Cookies</a>
        <a href="/cgu.php">CGU</a>
        <a href="/cgv.php">CGV</a>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> XynoWeb — Tous droits réservés</p>
      <p>Fait en France 🇫🇷</p>
    </div>
  </div>
</footer>

</body>
</html>
