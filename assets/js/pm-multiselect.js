/*!
 * PortalManager v1.9.30 — pm-multiselect
 * Componente drop-in: trasforma <select multiple class="pm-ms"> in un
 * multi-select con barra di ricerca testuale, click per selezionare (senza Ctrl).
 * Zero dipendenze (vanilla JS). Compatibile con qualsiasi form POST/GET esistente.
 *
 * Uso in HTML:
 *   <select multiple class="pm-ms" name="operator[]" data-placeholder="Incaricati...">
 *     <option value="1">Rossi Mario</option>
 *     ...
 *   </select>
 *
 * Attributi opzionali:
 *   data-placeholder     testo mostrato quando nessuna selezione
 *   data-search-min      soglia caratteri per attivare la ricerca (default 1)
 *   data-empty           testo mostrato quando nessun risultato (default "Nessun risultato")
 *   data-allow-clear     se presente, mostra la X per svuotare
 */
(function () {
  'use strict';
  const NS = 'pm-ms';

  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function enhance(sel) {
    if (sel.dataset.pmEnhanced === '1') return;
    sel.dataset.pmEnhanced = '1';
    if (!sel.multiple) sel.multiple = true;

    const placeholder = sel.dataset.placeholder || 'Seleziona…';
    const emptyText   = sel.dataset.empty || 'Nessun risultato';
    const searchMin   = parseInt(sel.dataset.searchMin || '1', 10);
    const allowClear  = sel.hasAttribute('data-allow-clear');

    // Nascondi la select originale ma tienila per il POST
    sel.style.position = 'absolute';
    sel.style.left = '-9999px';
    sel.style.width = '1px';
    sel.style.height = '1px';
    sel.tabIndex = -1;

    const wrap = document.createElement('div');
    wrap.className = NS + '-wrap';
    wrap.tabIndex = 0;

    const trigger = document.createElement('div');
    trigger.className = NS + '-trigger';
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

    function options() {
      return Array.from(sel.options);
    }

    function renderTrigger() {
      trigger.innerHTML = '';
      const picked = options().filter(o => o.selected);
      if (!picked.length) {
        const ph = document.createElement('span');
        ph.className = NS + '-placeholder';
        ph.textContent = placeholder;
        trigger.appendChild(ph);
      } else {
        picked.forEach(o => {
          const chip = document.createElement('span');
          chip.className = NS + '-chip';
          chip.innerHTML = esc(o.text) + ' <button type="button" aria-label="Rimuovi">&times;</button>';
          chip.querySelector('button').addEventListener('click', ev => {
            ev.stopPropagation();
            o.selected = false;
            fire();
          });
          trigger.appendChild(chip);
        });
      }
      if (allowClear && picked.length) {
        const x = document.createElement('button');
        x.type = 'button';
        x.className = NS + '-clear';
        x.setAttribute('aria-label', 'Svuota selezione');
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
      const opts = options();
      let visible = 0;
      opts.forEach(o => {
        if (term.length >= searchMin && !o.text.toLowerCase().includes(term)) return;
        visible++;
        const li = document.createElement('li');
        li.className = NS + '-item' + (o.selected ? ' selected' : '');
        li.setAttribute('role', 'option');
        li.dataset.value = o.value;
        li.innerHTML = '<span class="' + NS + '-tick">✓</span>' + esc(o.text);
        li.addEventListener('mousedown', ev => {
          ev.preventDefault();
          o.selected = !o.selected;
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

    function open() {
      dropdown.hidden = false;
      wrap.classList.add('open');
      renderList(search.value);
      setTimeout(() => search.focus(), 0);
    }
    function close() {
      dropdown.hidden = true;
      wrap.classList.remove('open');
    }
    function toggle() { dropdown.hidden ? open() : close(); }

    function fire() {
      renderTrigger();
      renderList(search.value);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    trigger.addEventListener('click', ev => {
      // Se ha cliccato una X di un chip, ignora il toggle
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

  function init(root) {
    (root || document).querySelectorAll('select.' + NS).forEach(enhance);
  }

  document.addEventListener('DOMContentLoaded', () => init());
  window.PmMultiselect = { init: init, enhance: enhance };
})();
