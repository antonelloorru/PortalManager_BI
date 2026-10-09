/*! PortalManager ATS — pm-ats.js · @version 1.3.5 */
/* PortalManager ATS — miglioramenti progressivi del modulo (il modulo funziona anche senza JavaScript). */
(function () {
  'use strict';
  var C = window.PM_ATS || {};
  var I = C.i18n || {};

  // token del modulo sempre fresco (le pagine possono essere servite da cache)
  var tokens = document.querySelectorAll('[data-pm-ats-token]');
  if (tokens.length && C.tokenUrl && window.fetch) {
    fetch(C.tokenUrl, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.token) tokens.forEach(function (t) { t.value = j.token; }); })
      .catch(function () {});
  }

  // filtri: invio automatico alla scelta
  document.querySelectorAll('.pm-ats-filters [data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // file: nome, dimensione e formato
  document.querySelectorAll('.pm-ats-file input[type=file]').forEach(function (inp) {
    var box = inp.closest('.pm-ats-file'), name = box.querySelector('.pm-ats-file-name');
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      inp.setCustomValidity('');
      if (!f) { box.classList.remove('has-file'); name.textContent = name.getAttribute('data-empty'); return; }
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (C.types && C.types.indexOf(ext) < 0) inp.setCustomValidity(I.badType || 'Formato non ammesso');
      else if (C.maxBytes && f.size > C.maxBytes) inp.setCustomValidity(I.tooLarge || 'File troppo grande');
      box.classList.add('has-file');
      name.textContent = f.name + ' (' + (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB)';
      if (inp.validationMessage) inp.reportValidity();
    });
  });

  // invio: validazione nativa, niente doppio invio
  document.querySelectorAll('form.pm-ats-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!form.checkValidity()) {
        e.preventDefault();
        var bad = form.querySelector(':invalid');
        form.querySelectorAll('[aria-invalid]').forEach(function (x) { x.removeAttribute('aria-invalid'); });
        if (bad) { bad.setAttribute('aria-invalid', 'true'); bad.reportValidity(); }
        return;
      }
      var b = form.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = I.sending || 'Invio…'; }
    });
  });

  // v1.3.0 — fisarmonica (layout «Lavora con noi»): una voce aperta alla volta, × per chiudere, tastiera con il pulsante
  document.querySelectorAll('[data-pm-ats-accordion]').forEach(function (acc) {
    var items = acc.querySelectorAll('[data-pm-ats-item]');
    function set(it, open) {
      var b = it.querySelector('.pm-ats-wt-toggle'), c = it.querySelector('.pm-ats-wt-content');
      it.classList.toggle('is-open', open); b.setAttribute('aria-expanded', open ? 'true' : 'false'); c.hidden = !open;
    }
    items.forEach(function (it) {
      it.querySelector('.pm-ats-wt-toggle').addEventListener('click', function () {
        var open = !it.classList.contains('is-open');
        items.forEach(function (o) { if (o !== it) set(o, false); });
        set(it, open);
      });
      var x = it.querySelector('.pm-ats-wt-close');
      if (x) x.addEventListener('click', function (e) { e.stopPropagation(); set(it, false); it.querySelector('.pm-ats-wt-toggle').focus(); });
    });
    // apertura diretta da #pm-ats-job-<id>
    if (location.hash && /^#pm-ats-job-\d+$/.test(location.hash)) {
      var t = acc.querySelector(location.hash);
      if (t) set(t.closest('[data-pm-ats-item]'), true);
    }
  });
  // «Candidati per questa posizione»: preseleziona la posizione nel modulo a lato
  document.querySelectorAll('[data-pm-ats-apply]').forEach(function (a) {
    a.addEventListener('click', function () {
      var s = document.querySelector('[data-pm-ats-job]');
      if (s) { s.value = a.getAttribute('data-pm-ats-apply'); s.dispatchEvent(new Event('change')); setTimeout(function () { s.focus({ preventScroll: true }); }, 400); }
    });
  });

  // porta in vista l'esito dopo il redirect
  var a = document.querySelector('.pm-ats-alert');
  if (a) { a.scrollIntoView({ block: 'center' }); a.focus({ preventScroll: true }); }

  // v1.3.5 — testata a tutta larghezza della finestra (.pm-ats-wt-hero-full): larghezza = area utile della finestra (senza
  // barra di scorrimento verticale), margine sinistro = scostamento reale della testata dal bordo, ricalcolati a ogni
  // ridimensionamento. Gli antenati che tagliano il contenuto (overflow) vengono marcati .pm-ats-wt-hero-host.
  var heroes = document.querySelectorAll('.pm-ats-wt-hero-full');
  if (heroes.length) {
    var fit = function () {
      var vw = document.documentElement.clientWidth;
      heroes.forEach(function (h) {
        h.style.setProperty('--pm-ats-hero-ml', '0px');
        h.style.setProperty('--pm-ats-hero-w', vw + 'px');
        var left = h.getBoundingClientRect().left + (window.pageXOffset || 0) - (document.documentElement.getBoundingClientRect().left + (window.pageXOffset || 0));
        h.style.setProperty('--pm-ats-hero-ml', (-Math.round(left)) + 'px');
      });
    };
    heroes.forEach(function (h) {
      for (var p = h.parentElement; p && p !== document.body; p = p.parentElement) {
        var ov = window.getComputedStyle(p).overflowX;
        if (ov === 'hidden' || ov === 'clip') p.classList.add('pm-ats-wt-hero-host');
      }
    });
    var raf = 0;
    var later = function () { if (raf) return; raf = (window.requestAnimationFrame || setTimeout)(function () { raf = 0; fit(); }); };
    fit();
    window.addEventListener('resize', later);
    window.addEventListener('load', fit);
    if (window.ResizeObserver) new ResizeObserver(later).observe(document.body);
  }
})();
