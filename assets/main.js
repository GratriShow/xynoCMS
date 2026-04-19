const qs = (sel, root = document) => root.querySelector(sel);
const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function formatEUR(value) {
  const rounded = Math.round(value * 100) / 100;
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(rounded);
}

function getQuery() {
  const p = new URLSearchParams(window.location.search);
  return Object.fromEntries(p.entries());
}

function setActiveNav() {
  const path = (window.location.pathname.split('/').pop() || 'index.php').toLowerCase();
  qsa('.nav-links a').forEach(a => {
    const href = (a.getAttribute('href') || '').toLowerCase();
    const hrefBase = (href.split('/').pop() || '').toLowerCase();
    if (hrefBase === path) a.setAttribute('aria-current', 'page');
  });
}

function initNavbarScroll() {
  const nav = qs('.navbar');
  if (!nav) return;
  const onScroll = () => {
    nav.classList.toggle('scrolled', window.scrollY > 8);
  };
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
}

function initBillingToggle(scope = document) {
  const toggles = qsa('[data-billing-toggle]', scope);
  toggles.forEach(toggle => {
    const buttons = qsa('button[data-billing]', toggle);
    const targetId = toggle.getAttribute('data-target');
    const target = targetId ? qs(`#${targetId}`) : document;

    const setBilling = (billing) => {
      buttons.forEach(b => b.setAttribute('aria-pressed', String(b.getAttribute('data-billing') === billing)));
      const evt = new CustomEvent('billingchange', { detail: { billing }, bubbles: true });
      toggle.dispatchEvent(evt);
      if (target && target !== toggle) {
        target.dispatchEvent(new CustomEvent('billingchange', { detail: { billing }, bubbles: true }));
      }
    };

    buttons.forEach(btn => {
      btn.addEventListener('click', () => setBilling(btn.getAttribute('data-billing')));
    });

    const initial = buttons.find(b => b.getAttribute('aria-pressed') === 'true')?.getAttribute('data-billing')
      || buttons[0]?.getAttribute('data-billing')
      || 'monthly';

    setBilling(initial);
  });
}

function initPricing() {
  const root = qs('[data-pricing-root]');
  if (!root) return;

  const cards = qsa('[data-plan]', root);
  const render = (billing) => {
    cards.forEach(card => {
      const monthly = Number(card.getAttribute('data-price-monthly'));
      const yearly = Number(card.getAttribute('data-price-yearly'));
      const priceEl = qs('[data-price]', card);
      const suffixEl = qs('[data-price-suffix]', card);
      const choose = qs('a[data-choose]', card);

      const price = billing === 'yearly' ? yearly : monthly;
      priceEl.textContent = String(price);
      suffixEl.textContent = billing === 'yearly' ? '/an' : '/mois';

      if (choose) {
        const url = new URL(choose.getAttribute('href'), window.location.href);
        url.searchParams.set('plan', card.getAttribute('data-plan'));
        url.searchParams.set('billing', billing);
        choose.setAttribute('href', url.pathname + '?' + url.searchParams.toString());
      }
    });

    const savings = qs('[data-yearly-savings]', root);
    if (savings) savings.hidden = billing !== 'yearly';
  };

  root.addEventListener('billingchange', (e) => render(e.detail.billing));
}

function initBuilder() {
  const wizard = qs('[data-builder]');
  if (!wizard) return;

  const query = getQuery();
  const steps = qsa('[data-step]');
  const stepLinks = qsa('[data-step-link]');
  const notice = qs('[data-notice]');

  const rawPlan = (query.plan || 'pro').toLowerCase();
  const planCompat = {
    starter: 'basic',
    studio: 'premium',
  };

  const state = {
    plan: (planCompat[rawPlan] || rawPlan),
    billing: (query.billing || 'monthly').toLowerCase(),
    theme: null,
    connection: 'microsoft',
    modules: new Set(),
    mcVersion: '1.21.4',
    loader: 'fabric',
    hosting: 'no',
    promo: '',
  };

  const PLAN = {
    basic: { monthly: 9, yearly: 86 },
    pro: { monthly: 19, yearly: 182 },
    premium: { monthly: 39, yearly: 374 },
  };

  const ADDONS = {
    modules: {
      modpack: 4,
      news: 2,
      discord: 2,
      autoupdate: 3,
      analytics: 3,
    },
    hostingMonthly: 9,
    hostingYearly: 86,
  };

  const PROMOS = {
    XYNO10: { type: 'percent', value: 10 },
    LAUNCH5: { type: 'fixed', value: 5 },
    FREE100: { type: 'percent', value: 100 },
  };

  let current = 1;

  const el = {
    planPill: qs('[data-plan-pill]'),
    billingPill: qs('[data-billing-pill]'),
    total: qs('[data-total]'),
    summary: qs('[data-summary]'),
    promoInput: qs('[data-promo]'),
    payBtn: qs('[data-pay]'),
  };

  const createForm = qs('[data-create-launcher]');
  const outTheme = qs('[data-out-theme]');
  const outVersion = qs('[data-out-version]');
  const outLoader = qs('[data-out-loader]');
  const outModules = qs('[data-out-modules]');
  const outPromo = qs('[data-out-promo]');

  function syncCreateForm() {
    if (!createForm) return;
    if (outTheme) outTheme.value = state.theme || '';
    if (outVersion) outVersion.value = state.mcVersion || '';
    if (outLoader) outLoader.value = state.loader || '';
    if (outModules) outModules.value = Array.from(state.modules).join(',');
    if (outPromo) outPromo.value = (state.promo || '').trim();
  }

  function showNotice(message) {
    if (!notice) return;
    notice.textContent = message;
    notice.setAttribute('data-show', 'true');
  }

  function clearNotice() {
    if (!notice) return;
    notice.setAttribute('data-show', 'false');
  }

  function go(step) {
    current = Math.max(1, Math.min(steps.length, step));
    steps.forEach(s => s.hidden = Number(s.getAttribute('data-step')) !== current);
    stepLinks.forEach(l => l.setAttribute('aria-current', Number(l.getAttribute('data-step-link')) === current ? 'step' : 'false'));
    clearNotice();
    updateUI();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function setChoice(listSelector, selectedSelector, value) {
    qsa(listSelector).forEach(c => {
      c.setAttribute('aria-selected', String(c.matches(selectedSelector)));
    });
  }

  function computeTotal() {
    const plan = PLAN[state.plan] || PLAN.pro;
    let subtotal = state.billing === 'yearly' ? plan.yearly : plan.monthly;

    let moduleAdd = 0;
    state.modules.forEach(m => { moduleAdd += (ADDONS.modules[m] || 0); });
    if (state.billing === 'yearly') moduleAdd = Math.round(moduleAdd * 12 * 0.8);

    subtotal += moduleAdd;

    if (state.hosting === 'yes') subtotal += (state.billing === 'yearly' ? ADDONS.hostingYearly : ADDONS.hostingMonthly);

    const promoKey = (state.promo || '').trim().toUpperCase();
    const promo = PROMOS[promoKey];
    let discount = 0;
    if (promo) {
      discount = promo.type === 'percent' ? (subtotal * (promo.value / 100)) : promo.value;
    }

    const total = Math.max(0, subtotal - discount);

    return { subtotal, discount, total, promoApplied: Boolean(promo) };
  }

  function updateUI() {
    if (el.planPill) el.planPill.textContent = state.plan.toUpperCase();
    if (el.billingPill) el.billingPill.textContent = state.billing === 'yearly' ? 'Annuel' : 'Mensuel';

    const { subtotal, discount, total, promoApplied } = computeTotal();

    if (el.total) el.total.textContent = formatEUR(total);

    if (el.summary) {
      // Le builder ne gère plus le pricing (plan, facturation, hébergement, promo).
      // On n'affiche que les infos de configuration du launcher.
      el.summary.innerHTML = `
        <div class="summary-row"><span>Thème</span><strong>${state.theme ? state.theme : '—'}</strong></div>
        <div class="summary-row"><span>Connexion</span><strong>${state.connection}</strong></div>
        <div class="summary-row"><span>Modules</span><strong>${state.modules.size ? Array.from(state.modules).join(', ') : 'Aucun'}</strong></div>
        <div class="summary-row"><span>Minecraft</span><strong>${state.mcVersion} • ${state.loader}</strong></div>
      `;
    }

    if (el.payBtn) {
      if (total <= 0) {
        el.payBtn.textContent = 'Gratuit — Continuer';
        el.payBtn.setAttribute('href', 'dashboard.php');
        el.payBtn.setAttribute('data-free', 'true');
      } else {
        el.payBtn.textContent = `Payer ${formatEUR(total)}`;
        el.payBtn.setAttribute('href', '#');
        el.payBtn.removeAttribute('data-free');
      }
    }

    syncCreateForm();
  }

  function validateStep(step) {
    if (step === 1 && !state.theme) {
      showNotice('Choisis un thème pour continuer.');
      return false;
    }
    return true;
  }

  if (createForm) {
    createForm.addEventListener('submit', (e) => {
      syncCreateForm();
      if (!state.theme) {
        e.preventDefault();
        showNotice('Choisis un thème pour enregistrer ton launcher.');
        go(1);
      }
    });
  }

  // Theme selection
  qsa('[data-theme]').forEach(card => {
    card.addEventListener('click', () => {
      state.theme = card.getAttribute('data-theme');
      qsa('[data-theme]').forEach(c => c.setAttribute('aria-selected', String(c === card)));
      updateUI();
      clearNotice();
    });
  });

  // Hosting selection
  qsa('[data-hosting]').forEach(card => {
    card.addEventListener('click', () => {
      state.hosting = card.getAttribute('data-hosting');
      qsa('[data-hosting]').forEach(c => c.setAttribute('aria-selected', String(c === card)));
      updateUI();
    });
  });

  // Form inputs
  const connection = qs('[data-connection]');
  if (connection) {
    connection.addEventListener('change', () => {
      state.connection = connection.value;
      updateUI();
    });
  }

  const version = qs('[data-mc-version]');
  if (version) {
    version.addEventListener('change', () => {
      state.mcVersion = version.value;
      updateUI();
    });
  }

  const loader = qs('[data-loader]');
  if (loader) {
    loader.addEventListener('change', () => {
      state.loader = loader.value;
      updateUI();
    });
  }

  qsa('input[type="checkbox"][data-module]').forEach(cb => {
    cb.addEventListener('change', () => {
      const mod = cb.getAttribute('data-module');
      if (cb.checked) state.modules.add(mod); else state.modules.delete(mod);
      updateUI();
    });
  });

  if (el.promoInput) {
    el.promoInput.addEventListener('input', () => {
      state.promo = el.promoInput.value;
      updateUI();
    });
  }

  // Billing toggle inside builder (step 5)
  const billingToggle = qs('[data-billing-toggle]');
  if (billingToggle) {
    billingToggle.addEventListener('billingchange', (e) => {
      state.billing = e.detail.billing;
      updateUI();
    });

    // set initial
    qsa('button[data-billing]', billingToggle).forEach(btn => {
      const billing = btn.getAttribute('data-billing');
      btn.setAttribute('aria-pressed', String(billing === state.billing));
    });
    billingToggle.dispatchEvent(new CustomEvent('billingchange', { detail: { billing: state.billing } }));
  }

  // Plan display (from query)
  const planSelect = qs('[data-plan-select]');
  if (planSelect) {
    planSelect.value = state.plan;
    planSelect.addEventListener('change', () => {
      state.plan = planSelect.value;
      updateUI();
    });
  }

  // Next/Back
  qsa('[data-next]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!validateStep(current)) return;
      go(current + 1);
    });
  });

  qsa('[data-back]').forEach(btn => {
    btn.addEventListener('click', () => go(current - 1));
  });

  stepLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const dest = Number(link.getAttribute('data-step-link'));
      if (dest > current && !validateStep(current)) return;
      go(dest);
    });
  });

  // Pay CTA (UI only)
  if (el.payBtn) {
    el.payBtn.addEventListener('click', (e) => {
      const { total } = computeTotal();
      if (total <= 0 || el.payBtn.getAttribute('data-free') === 'true') {
        // Free flow: no payment step.
        return;
      }

      e.preventDefault();
      updateUI();
      showNotice('UI uniquement : branche ce bouton à ton paiement (Stripe, etc.).');
    });
  }

  // Initial render
  updateUI();
  go(1);
}

function initUploadPage() {
  const root = qs('[data-upload-page]');
  if (!root) return;

  const form = qs('[data-upload-form]', root);
  if (!form) return;

  const typeSel = qs('[data-upload-type]', form);
  const moduleWrap = qs('[data-upload-module]', form);
  const versionWrap = qs('[data-upload-mc-version]', form);
  const dropzone = qs('[data-dropzone]', form);
  const fileInput = qs('input[type="file"][name="file"]', form);

  const progressWrap = qs('[data-upload-progress]', form);
  const bar = qs('[data-upload-bar]', form);
  const label = qs('[data-upload-label]', form);

  const sync = () => {
    const type = (typeSel?.value || '').toLowerCase();
    if (moduleWrap) moduleWrap.style.display = (type === 'config' || type === 'asset') ? '' : 'none';
    if (versionWrap) versionWrap.style.display = (type === 'version') ? '' : 'none';
  };

  if (typeSel) {
    typeSel.addEventListener('change', sync);
    sync();
  }

  if (dropzone && fileInput) {
    const prevent = (e) => { e.preventDefault(); e.stopPropagation(); };
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, prevent));

    dropzone.addEventListener('dragover', () => { dropzone.style.borderStyle = 'solid'; });
    dropzone.addEventListener('dragleave', () => { dropzone.style.borderStyle = 'dashed'; });

    dropzone.addEventListener('drop', (e) => {
      dropzone.style.borderStyle = 'dashed';
      const files = e.dataTransfer?.files;
      if (files && files.length) fileInput.files = files;
    });
  }

  form.addEventListener('submit', (e) => {
    if (!window.XMLHttpRequest || !window.FormData) return;
    if (!fileInput || !fileInput.files || !fileInput.files.length) return;

    e.preventDefault();

    if (progressWrap) progressWrap.style.display = '';
    if (bar) bar.style.width = '0%';
    if (label) label.textContent = '0%';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || window.location.href);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.onprogress = (evt) => {
      if (!evt.lengthComputable) return;
      const pct = Math.max(0, Math.min(100, Math.round((evt.loaded / evt.total) * 100)));
      if (bar) bar.style.width = pct + '%';
      if (label) label.textContent = pct + '%';
    };

    xhr.onerror = () => {
      alert('Erreur réseau pendant l\'upload.');
      if (progressWrap) progressWrap.style.display = 'none';
    };

    xhr.onload = () => {
      try {
        const data = JSON.parse(xhr.responseText || '{}');
        if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
          window.location.href = data.redirect || window.location.href;
          return;
        }
        alert(data.message || data.error || 'Upload impossible.');
      } catch {
        alert('Upload impossible.');
      }
      if (progressWrap) progressWrap.style.display = 'none';
    };

    xhr.send(new FormData(form));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  setActiveNav();
  initNavbarScroll();
  initBillingToggle(document);
  initPricing();
  initBuilder();
  initUploadPage();
});
