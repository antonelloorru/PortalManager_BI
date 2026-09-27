/*!
 * PortalManager v1.9.71 — pm-multiselect v2
 * Select con barra di ricerca integrata, applicata AUTOMATICAMENTE in tutto il portale.
 *
 *  - <select multiple>                      → multi-select: ricerca, spunte, "Tutti/Nessuno", chip
 *  - <select class="pm-ms"> (singola)       → select singola con ricerca (NON diventa multipla:
 *                                             il server si aspetta un solo valore)
 *  - <select> a scelta singola nei form di FILTRO (method=get) con molte opzioni → ricerca
 *
 * La <select> nativa resta nel DOM ed è sempre la fonte di verità: invio del form,
 * onchange="this.form.submit()", reset e JavaScript esistente continuano a funzionare.
 *
 * Esclusione: data-pm-ms="off" sulla select o su un contenitore.
 * Attributi: data-placeholder, data-empty, data-allow-clear, data-search-min.
 * API: PmMultiselect.init(root), .enhance(select), .refresh(select)
 * Compatibile con la v1.9.30 (class="pm-ms", window.PmMultiselect).
 */
(function () {
  'use strict';
  if (window.PmMultiselect && window.PmMultiselect.version === 2) return;   // caricato due volte
  var NS = 'pm-ms';
  var AUTO_SINGLE_MIN = 8;          // opzioni oltre le quali una select di filtro ottiene la ricerca
  var openInstance = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function norm(s) {   // ricerca senza accenti e maiuscole
    return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }
  function isExcluded(sel) {
    return !!sel.closest('[data-pm-ms="off"]') || sel.closest('.lf-toolbar') ||
           sel.closest('.pm-ms-wrap') || sel.closest('.dataTables_length');
  }
  function eligible(sel) {
    if (sel.dataset.pmEnhanced === '1' || isExcluded(sel)) return false;
    if (sel.multiple || sel.classList.contains(NS)) return true;
    var form = sel.closest('form');
    var isFilterForm = form && (form.getAttribute('method') || 'get').toLowerCase() === 'get';
    return isFilterForm && sel.options.length > AUTO_SINGLE_MIN;
  }

  function enhance(sel) {
    if (sel.dataset.pmEnhanced === '1') return;
    sel.dataset.pmEnhanced = '1';
    var multi = sel.multiple;
    var placeholder = sel.dataset.placeholder || (multi ? 'Tutti' : '');
    var emptyText   = sel.dataset.empty || 'Nessun risultato';
    var searchMin   = parseInt(sel.dataset.searchMin || '1', 10);
    var allowClear  = sel.hasAttribute('data-allow-clear') || multi;

    // la select nativa resta nel form (invio, reset, validazione) ma non è visibile
    sel.classList.add(NS + '-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    var wrap = document.createElement('div');
    wrap.className = NS + '-wrap' + (multi ? ' ' + NS + '-multi' : ' ' + NS + '-single');
    wrap.tabIndex = sel.disabled ? -1 : 0;
    wrap.setAttribute('role', 'combobox');
    wrap.setAttribute('aria-expanded', 'false');
    var lbl = sel.id && document.querySelector('label[for="' + sel.id + '"]');
    if (lbl) wrap.setAttribute('aria-label', lbl.textContent.trim());
    if (sel.style.width) wrap.style.width = sel.style.width;
    if (sel.style.minWidth) wrap.style.minWidth = sel.style.minWidth;

    var trigger = document.createElement('div'); trigger.className = NS + '-trigger'; wrap.appendChild(trigger);
    var dropdown = document.createElement('div'); dropdown.className = NS + '-dropdown'; dropdown.hidden = true;
    var search = document.createElement('input');
    search.type = 'search'; search.className = NS + '-search'; search.placeholder = 'Cerca…'; search.autocomplete = 'off';
    dropdown.appendChild(search);
    var tools = null;
    if (multi) {
      tools = document.createElement('div'); tools.className = NS + '-tools';
      tools.innerHTML = '<button type="button" data-a="all">Seleziona visibili</button>' +
                        '<button type="button" data-a="none">Nessuno</button>' +
                        '<span class="' + NS + '-count"></span>';
      dropdown.appendChild(tools);
    }
    var list = document.createElement('ul'); list.className = NS + '-list'; list.setAttribute('role', 'listbox');
    if (multi) list.setAttribute('aria-multiselectable', 'true');
    dropdown.appendChild(list);
    wrap.appendChild(dropdown);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);

    var active = -1;              // indice evidenziato con la tastiera
    function opts() { return Array.prototype.filter.call(sel.options, function (o) { return !o.hidden; }); }
    function isBlank(o) { return o.value === ''; }  // "— tutte —", "Seleziona…"

    function renderTrigger() {
      trigger.innerHTML = '';
      var picked = opts().filter(function (o) { return o.selected && !(multi && isBlank(o)); });
      if (multi) {
        if (!picked.length) {
          trigger.insertAdjacentHTML('beforeend', '<span class="' + NS + '-placeholder">' + esc(placeholder) + '</span>');
        } else {
          picked.slice(0, 3).forEach(function (o) {
            var chip = document.createElement('span'); chip.className = NS + '-chip'; chip.title = o.text;
            chip.innerHTML = '<span>' + esc(o.text) + '</span><button type="button" aria-label="Rimuovi ' + esc(o.text) + '">&times;</button>';
            chip.querySelector('button').addEventListener('click', function (ev) { ev.stopPropagation(); o.selected = false; fire(); });
            trigger.appendChild(chip);
          });
          if (picked.length > 3) trigger.insertAdjacentHTML('beforeend', '<span class="' + NS + '-more">+' + (picked.length - 3) + '</span>');
        }
      } else {
        var cur = sel.options[sel.selectedIndex];
        var txt = cur ? cur.text : '';
        trigger.insertAdjacentHTML('beforeend', '<span class="' + NS + (txt ? '-value' : '-placeholder') + '">' + esc(txt || placeholder || '—') + '</span>');
      }
      if (allowClear && multi && picked.length) {
        var x = document.createElement('button');
        x.type = 'button'; x.className = NS + '-clear'; x.setAttribute('aria-label', 'Svuota selezione'); x.innerHTML = '&times;';
        x.addEventListener('click', function (ev) { ev.stopPropagation(); opts().forEach(function (o) { o.selected = false; }); fire(); });
        trigger.appendChild(x);
      }
      trigger.insertAdjacentHTML('beforeend', '<span class="' + NS + '-caret">▾</span>');
      wrap.classList.toggle(NS + '-has-value', multi ? picked.length > 0 : !!(cur && !isBlank(cur)));
    }

    function visibleItems() { return Array.prototype.slice.call(list.querySelectorAll('.' + NS + '-item')); }

    function renderList() {
      list.innerHTML = '';
      var term = norm(search.value.trim());
      var shown = 0, lastGroup = null;
      opts().forEach(function (o) {
        if (multi && isBlank(o)) return;                       // "— tutti —" non ha senso in multi
        if (term.length >= searchMin && norm(o.text).indexOf(term) === -1) return;
        var grp = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label : null;
        if (grp !== lastGroup && grp) {
          list.insertAdjacentHTML('beforeend', '<li class="' + NS + '-group">' + esc(grp) + '</li>');
        }
        lastGroup = grp;
        shown++;
        var li = document.createElement('li');
        li.className = NS + '-item' + (o.selected ? ' selected' : '') + (o.disabled ? ' disabled' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        li.innerHTML = (multi ? '<span class="' + NS + '-box"></span>' : '<span class="' + NS + '-tick">✓</span>') + esc(o.text);
        li.__opt = o;
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(o); });
        list.appendChild(li);
      });
      if (!shown) list.insertAdjacentHTML('beforeend', '<li class="' + NS + '-empty">' + esc(emptyText) + '</li>');
      if (tools) {
        var n = opts().filter(function (o) { return o.selected && !isBlank(o); }).length;
        tools.querySelector('.' + NS + '-count').textContent = n ? n + ' selezionati' : '';
      }
      active = -1;
    }

    function choose(o) {
      if (o.disabled) return;
      if (multi) { o.selected = !o.selected; fire(false); }
      else { sel.value = o.value; fire(true); }
    }
    function fire(closeAfter) {
      renderTrigger(); renderList();
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));   // attiva anche onchange="this.form.submit()"
      if (closeAfter) close();
    }
    function open() {
      if (sel.disabled) return;
      if (openInstance && openInstance !== api) openInstance.close();
      openInstance = api;
      renderTrigger();                     // riallinea con modifiche fatte da altro JS
      dropdown.hidden = false; wrap.classList.add('open'); wrap.setAttribute('aria-expanded', 'true');
      search.value = ''; renderList();
      // se non c'è spazio sotto, apre verso l'alto
      var r = wrap.getBoundingClientRect();
      wrap.classList.toggle(NS + '-up', window.innerHeight - r.bottom < 300 && r.top > 300);
      setTimeout(function () { search.focus(); }, 0);
    }
    function close() {
      if (dropdown.hidden) return;
      dropdown.hidden = true; wrap.classList.remove('open'); wrap.setAttribute('aria-expanded', 'false');
      if (openInstance === api) openInstance = null;
    }
    function move(d) {
      var items = visibleItems(); if (!items.length) return;
      active = Math.max(0, Math.min(items.length - 1, active + d));
      items.forEach(function (it, i) { it.classList.toggle('active', i === active); });
      items[active].scrollIntoView({ block: 'nearest' });
    }

    trigger.addEventListener('click', function (ev) { if (ev.target.closest('button')) return; dropdown.hidden ? open() : close(); });
    wrap.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { close(); wrap.focus(); return; }
      if (ev.key === 'ArrowDown') { ev.preventDefault(); if (dropdown.hidden) open(); else move(1); return; }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); move(-1); return; }
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (dropdown.hidden) { open(); return; }
        var items = visibleItems();
        var it = items[active >= 0 ? active : 0];
        if (it) choose(it.__opt);
        return;
      }
      if (ev.key === ' ' && ev.target === wrap) { ev.preventDefault(); open(); }
    });
    search.addEventListener('input', renderList);
    if (tools) {
      tools.addEventListener('mousedown', function (ev) {
        var b = ev.target.closest('button'); if (!b) return;
        ev.preventDefault();
        if (b.dataset.a === 'all') {
          visibleItems().forEach(function (it) { if (!it.__opt.disabled) it.__opt.selected = true; });
        } else {
          opts().forEach(function (o) { o.selected = false; });
        }
        fire(false);
      });
    }
    // la select nativa cambiata da altro codice (reset form, JS di pagina)
    sel.addEventListener('change', function (ev) { if (!ev.isTrusted) return; renderTrigger(); });
    var form = sel.form;
    if (form) form.addEventListener('reset', function () { setTimeout(renderTrigger, 0); });
    // opzioni ripopolate dinamicamente (select dipendenti)
    new MutationObserver(function () { renderTrigger(); if (!dropdown.hidden) renderList(); })
      .observe(sel, { childList: true, subtree: true });

    var api = { close: close, refresh: renderTrigger };
    sel.__pmms = api;
    renderTrigger();
  }

  document.addEventListener('click', function (ev) {
    if (openInstance && !ev.target.closest('.' + NS + '-wrap')) openInstance.close();
  });

  function init(root) {
    var scope = root || document;
    Array.prototype.forEach.call(scope.querySelectorAll('select'), function (s) { if (eligible(s)) enhance(s); });
  }
  function refresh(sel) { if (sel && sel.__pmms) sel.__pmms.refresh(); }

  function start() {
    init();
    // select aggiunte dopo il caricamento (modali, righe dinamiche)
    new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) {
          if (n.nodeType !== 1) return;
          if (n.tagName === 'SELECT') { if (eligible(n)) enhance(n); }
          else if (n.querySelectorAll) init(n);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

  window.PmMultiselect = { version: 2, init: init, enhance: enhance, refresh: refresh };
})();
