/* PortalManager v1.9.24 — Careers Portal (frontend esterno)
 * IMPORTANTE: la firma HMAC NON viene calcolata qui. Il browser non può
 * detenere il secret. Le fetch vanno verso il BFF (Backend-for-Frontend)
 * dell'host del portale esterno, che a sua volta firma le richieste al
 * gestionale HR. Vedi careers_bff.php.
 */
(() => {
  'use strict';

  const BFF = {
    positions:   '/careers/bff.php?op=positions',
    checkEmail:  '/careers/bff.php?op=check_email',
    apply:       '/careers/bff.php?op=apply',
  };

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const state = { positions: [], selected: null };

  document.addEventListener('DOMContentLoaded', () => {
    $('#year').textContent = new Date().getFullYear();
    loadPositions();

    $('#f-reload').addEventListener('click', loadPositions);
    ['#f-q','#f-dept','#f-loc'].forEach(sel => $(sel).addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); loadPositions(); }
    }));

    $('#apply-form').addEventListener('submit', submitApplication);
    $('#btn-cancel').addEventListener('click', () => {
      $('#apply').hidden = true;
      state.selected = null;
      $('#apply-form').reset();
      $('#email-status').textContent = '';
      window.scrollTo({ top: $('#positions').offsetTop - 20, behavior: 'smooth' });
    });

    let t;
    $('#apply-form email').forEach?.(() => {});
    const emailEl = $('#apply-form [name="email"]');
    emailEl.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => checkEmail(emailEl.value.trim()), 500);
    });
  });

  async function loadPositions() {
    const q    = encodeURIComponent($('#f-q').value.trim());
    const dept = encodeURIComponent($('#f-dept').value.trim());
    const loc  = encodeURIComponent($('#f-loc').value.trim());
    const url  = `${BFF.positions}&q=${q}&department=${dept}&location=${loc}`;

    setListMessage('Caricamento…');
    try {
      const r = await fetch(url, { credentials: 'omit', cache: 'no-store' });
      const data = await r.json();
      if (!data.ok) throw new Error(data.error || 'errore');
      state.positions = data.items || [];
      renderPositions(data.total, state.positions);
    } catch (err) {
      setListMessage('Errore nel caricamento delle posizioni. Riprova più tardi.');
      console.error(err);
    }
  }

  function renderPositions(total, items) {
    $('#pos-count').textContent = String(total);
    const box = $('#pos-list');
    if (!items.length) {
      box.innerHTML = '<p class="muted">Nessuna posizione aperta al momento.</p>';
      return;
    }
    box.innerHTML = items.map(p => `
      <article class="pos-card">
        <header>
          <h3>${escapeHtml(p.title)}</h3>
          <div class="meta">
            ${p.department ? `<span>${escapeHtml(p.department)}</span>` : ''}
            ${p.location   ? `<span>${escapeHtml(p.location)}</span>`   : ''}
            ${p.contract_type ? `<span>${escapeHtml(p.contract_type)}</span>` : ''}
            ${p.seniority  ? `<span>${escapeHtml(p.seniority)}</span>`  : ''}
          </div>
        </header>
        <p class="desc">${escapeHtml(truncate(p.description || '', 320))}</p>
        <button class="btn-apply" data-id="${p.id}" data-title="${escapeAttr(p.title)}">Candidati</button>
      </article>`).join('');

    $$('.btn-apply', box).forEach(btn => btn.addEventListener('click', e => {
      const id    = e.currentTarget.getAttribute('data-id');
      const title = e.currentTarget.getAttribute('data-title');
      openApplyForm(id, title);
    }));
  }

  function openApplyForm(id, title) {
    state.selected = id;
    $('#apply-position-id').value = id;
    $('#apply-title').textContent = title;
    $('#apply').hidden = false;
    window.scrollTo({ top: $('#apply').offsetTop - 20, behavior: 'smooth' });
  }

  async function checkEmail(email) {
    const hint = $('#email-status');
    hint.textContent = '';
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
    try {
      const r = await fetch(BFF.checkEmail, {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });
      const d = await r.json();
      if (!d.ok) return;
      if (d.is_employee)             hint.textContent = 'Sembri già un nostro dipendente: contatta HR.';
      else if (d.has_active_application) hint.textContent = 'Risulta una candidatura attiva a tuo nome.';
      else if (d.known)              hint.textContent = 'Bentornato: aggiorneremo il tuo profilo.';
      else                           hint.textContent = 'Nuovo candidato, benvenuto.';
    } catch { /* silente */ }
  }

  async function submitApplication(ev) {
    ev.preventDefault();
    const btn = $('#btn-submit');
    const status = $('#apply-status');
    const form = ev.currentTarget;
    if (!form.reportValidity()) return;

    btn.disabled = true;
    status.textContent = 'Invio in corso…';
    status.className = 'status';

    try {
      const fd = new FormData(form);
      // Assicura invio '0' per checkbox non spuntate
      if (!fd.has('consent_marketing')) fd.set('consent_marketing', '0');
      const r = await fetch(BFF.apply, { method: 'POST', body: fd, credentials: 'omit' });
      const d = await r.json();
      if (!r.ok || !d.ok) {
        status.className = 'status err';
        status.textContent = 'Errore: ' + friendlyError(d.error || ('http_' + r.status));
        return;
      }
      status.className = 'status ok';
      status.textContent = `Candidatura ricevuta. Riferimento: ${d.reference}`;
      form.reset();
    } catch (err) {
      status.className = 'status err';
      status.textContent = 'Errore di rete. Riprova più tardi.';
      console.error(err);
    } finally {
      btn.disabled = false;
    }
  }

  function friendlyError(code) {
    const map = {
      application_already_active: 'Hai già una candidatura attiva per questa posizione.',
      position_not_open:          'La posizione non è più disponibile.',
      cv_too_large:               'Il CV supera la dimensione massima consentita.',
      privacy_consent_required:   'Devi accettare l\'informativa privacy.',
      rate_limited:               'Troppe richieste ravvicinate. Riprova più tardi.',
      invalid_email:              'Indirizzo email non valido.',
    };
    return map[code] || code;
  }

  function truncate(s, n) { return s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s; }
  function escapeHtml(s)  { return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
  function escapeAttr(s)  { return escapeHtml(s); }
  function setListMessage(msg) { $('#pos-list').innerHTML = `<p class="muted">${escapeHtml(msg)}</p>`; }
})();
