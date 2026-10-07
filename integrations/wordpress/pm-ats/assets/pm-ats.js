/*! PortalManager ATS — pm-ats.js · @version 1.1.0 */
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

  // porta in vista l'esito dopo il redirect
  var a = document.querySelector('.pm-ats-alert');
  if (a) { a.scrollIntoView({ block: 'center' }); a.focus({ preventScroll: true }); }
})();
