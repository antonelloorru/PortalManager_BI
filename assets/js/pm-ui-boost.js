/*!
 * PortalManager v1.9.31 — pm-ui-boost
 * Drop-in per select esistenti: aggiunge barra di ricerca e (opzionale) modo
 * multi-select con click semplice (senza Ctrl). Riordina alfabeticamente le
 * label in formato "Cognome Nome" quando le option arrivano dal server come
 * "Nome Cognome" (parsing conservativo, value invariato).
 *
 * Uso minimo — aggiungi solo la classe "pm-ms" a un <select> esistente:
 *   <select name="tec" class="pm-ms">…</select>          (single, con search)
 *   <select name="tec[]" multiple class="pm-ms">…</select>  (multi)
 *
 * Attributi opzionali:
 *   data-placeholder     testo mostrato quando nessuna selezione
 *   data-search-min      soglia char per attivare filtro (default 1)
 *   data-empty           testo lista vuota (default "Nessun risultato")
 *   data-allow-clear     mostra la X globale
 *   data-no-reorder      disattiva il riordino "Cognome Nome"
 *   data-order-mode      "cognome-nome" (default) | "as-is"
 *
 * Auto-init: al DOMContentLoaded processa tutte le "select.pm-ms".
 */
(function () {
  'use strict';
  const NS = 'pm-ms';

  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

  // ── Reorder "Cognome Nome" ────────────────────────────────────────────
  // Se una label è composta da 2 sole "parole" e nessuna contiene "|", "—", "-", "@"
  // la trattiamo come "Nome Cognome" e la mostriamo come "Cognome Nome".
  // Le altre restano invariate.
  function toCognomeNome(label) {
    const s = (label || '').trim();
    if (!s) return s;
    if (/[|—@,;·]|\bL[12]\b|\d/.test(s)) return s;  // etichette composite non toccate
    const parts = s.split(/\s+/);
    if (parts.length !== 2) return s;
    const [a, b] = parts;
    // heuristic: parole capitalizzate senza numeri
    if (!/^[A-ZÀ-Ý][a-zà-ÿ'’]/.test(a) || !/^[A-ZÀ-Ý][a-zà-ÿ'’]/.test(b)) return s;
    return b + ' ' + a;
  }

  function reorderOptions(sel) {
    if (sel.dataset.noReorder === '1' || sel.dataset.orderMode === 'as-is') return;
    // Preserva le option "sentinella" (value vuoto, tipo "— tutte —")
    const opts = Array.from(sel.options);
    const sentinels = opts.filter(o => o.value === '');
    const data = opts.filter(o => o.value !== '').map(o => ({
      value: o.value,
      original: o.text,
      display: toCognomeNome(o.text),
      selected: o.selected,
      disabled: o.disabled,
      title: o.title,
    }));
    data.sort((x, y) => x.display.localeCompare(y.display, 'it', { sensitivity: 'base' }));
    sel.innerHTML = '';
    sentinels.forEach(o => sel.appendChild(o));
    data.forEach(d => {
      const o = document.createElement('option');
      o.value = d.value;
      o.text = d.display;
      o.title = d.original !== d.display ? d.original : (d.title || '');
      if (d.selected) o.selected = true;
      if (d.disabled) o.disabled = true;
      sel.appendChild(o);
    });
  }

  // ── Enhance select ────────────────────────────────────────────────────
  function enhance(sel) {
    if (sel.dataset.pmEnhanced === '1') return;
    sel.dataset.pmEnhanced = '1';

    reorderOptions(sel);

    const isMulti     = sel.multiple;
    const placeholder = sel.dataset.placeholder
                      || (sel.options[0] && sel.options[0].value === '' ? sel.options[0].text : (isMulti ? 'Seleziona…' : ''));
    const emptyText   = sel.dataset.empty || 'Nessun risultato';
    const searchMin   = parseInt(sel.dataset.searchMin || '1', 10);
    const allowClear  = sel.hasAttribute('data-allow-clear');

    // Nascondi la select originale ma mantienila per il form submit
    sel.style.position = 'absolute';
    sel.style.left = '-9999px';
    sel.style.width = '1px';
    sel.style.height = '1px';
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    const wrap = document.createElement('div');
    wrap.className = NS + '-wrap';
    wrap.tabIndex = 0;

    const trigger = document.createElement('div');
    trigger.className = NS + '-trigger' + (isMulti ? ' multi' : ' single');
    wrap.appendChild(trigger);

    const dropdown = document.createElement('div');
    dropdown.className = NS + '-dropdown';
    dropdown.hidden = true;

    const search = document.createElement('input');
    search.type = 'search';
    search.className = NS + '-search';
    search.placeholder = 'Cerca…';
    search.autocomplete = 'off';
    dropdown.appendChild(search);

    const list = document.createElement('ul');
    list.className = NS + '-list';
    list.setAttribute('role', 'listbox');
    dropdown.appendChild(list);

    wrap.appendChild(dropdown);
    sel.parentNode.insertBefore(wrap, sel.nextSibling);

    const options = () => Array.from(sel.options);

    function renderTrigger() {
      trigger.innerHTML = '';
      const picked = options().filter(o => o.selected && o.value !== '');
      if (!picked.length) {
        const ph = document.createElement('span');
        ph.className = NS + '-placeholder';
        ph.textContent = placeholder || '—';
        trigger.appendChild(ph);
      } else if (!isMulti) {
        // Single mode: mostra la label selezionata
        const t = document.createElement('span');
        t.className = NS + '-single-label';
        t.textContent = picked[0].text;
        trigger.appendChild(t);
      } else {
        picked.forEach(o => {
          const chip = document.createElement('span');
          chip.className = NS + '-chip';
          chip.innerHTML = esc(o.text) + ' <button type="button" aria-label="Rimuovi">&times;</button>';
          chip.querySelector('button').addEventListener('click', ev => {
            ev.stopPropagation(); o.selected = false; fire();
          });
          trigger.appendChild(chip);
        });
      }
      if (allowClear && picked.length) {
        const x = document.createElement('button');
        x.type = 'button';
        x.className = NS + '-clear';
        x.setAttribute('aria-label', 'Svuota');
        x.innerHTML = '&times;';
        x.addEventListener('click', ev => {
          ev.stopPropagation();
          options().forEach(o => (o.selected = false));
          fire();
        });
        trigger.appendChild(x);
      }
      const caret = document.createElement('span');
      caret.className = NS + '-caret';
      caret.textContent = '▾';
      trigger.appendChild(caret);
    }

    function renderList(q) {
      list.innerHTML = '';
      const term = (q || '').trim().toLowerCase();
      let visible = 0;
      options().forEach(o => {
        if (o.value === '' && !isMulti) return; // in single non serve mostrare "—" (già in placeholder)
        if (term.length >= searchMin && !o.text.toLowerCase().includes(term)) return;
        visible++;
        const li = document.createElement('li');
        li.className = NS + '-item' + (o.selected ? ' selected' : '') + (o.disabled ? ' disabled' : '');
        li.dataset.value = o.value;
        li.setAttribute('role', 'option');
        li.innerHTML = '<span class="' + NS + '-tick">✓</span>' + esc(o.text);
        if (!o.disabled) li.addEventListener('mousedown', ev => {
          ev.preventDefault();
          if (isMulti) o.selected = !o.selected;
          else { options().forEach(x => x.selected = false); o.selected = true; close(); }
          fire();
        });
        list.appendChild(li);
      });
      if (!visible) {
        const li = document.createElement('li');
        li.className = NS + '-empty';
        li.textContent = emptyText;
        list.appendChild(li);
      }
    }

    function open()  { dropdown.hidden = false; wrap.classList.add('open'); renderList(search.value); setTimeout(() => search.focus(), 0); }
    function close() { dropdown.hidden = true;  wrap.classList.remove('open'); }
    function toggle(){ dropdown.hidden ? open() : close(); }
    function fire()  { renderTrigger(); renderList(search.value); sel.dispatchEvent(new Event('change', { bubbles: true })); }

    trigger.addEventListener('click', ev => {
      if (ev.target.closest('button')) return;
      toggle();
    });
    wrap.addEventListener('keydown', ev => {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); }
      if (ev.key === 'Escape') close();
    });
    search.addEventListener('input', () => renderList(search.value));
    document.addEventListener('click', ev => { if (!wrap.contains(ev.target)) close(); });

    renderTrigger();
  }

  function init(root) { (root || document).querySelectorAll('select.' + NS).forEach(enhance); }

  // ── Auto-target: converte anche select senza classe se listate in data-pm-boost ─
  function autoBoost() {
    const meta = document.querySelector('meta[name="pm-ui-boost"]');
    if (meta && meta.content) {
      document.querySelectorAll(meta.content).forEach(s => {
        s.classList.add(NS);
        enhance(s);
      });
    }
    init();
  }

  document.addEventListener('DOMContentLoaded', autoBoost);
  window.PmUiBoost = { init, enhance, toCognomeNome, reorderOptions };
})();
