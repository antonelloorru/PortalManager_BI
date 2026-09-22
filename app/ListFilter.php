<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 * PortalManager — app/ListFilter.php
 *
 * Componente UI riutilizzabile per filtri + ricerca + viste salvate +
 * esportazioni su qualsiasi pagina elenco del portale.
 *
 * Integrazione minimal-invasive — bastano 2 righe per attivare in una pagina:
 *
 *     require_once __DIR__ . '/app/ListFilter.php';
 *     ListFilter::render('manage_employees', '#employees-table');
 *
 * Il primo parametro identifica la pagina (per persistere le viste salvate).
 * Il secondo è il selettore della tabella su cui agisce il filtro.
 *
 * Caratteristiche:
 *  - Search bar testuale (filtra TUTTE le righe per qualsiasi parola)
 *  - Pannello filtri avanzati (auto-generato per colonna)
 *  - Viste salvate (set di filtri riutilizzabili)
 *  - Export CSV / XLSX / PDF (stampabile) / DOCX
 *  - Contatore righe visibili
 *  - Zero dipendenze (vanilla JS + CSS)
 * ════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

class ListFilter
{
    /** @var bool Per evitare doppia stampa CSS/JS se la classe viene chiamata più volte */
    private static bool $assets_printed = false;

    /**
     * Stampa il pannello filtri + ricerca + export sopra la tabella indicata.
     *
     * @param string $page_name   Identificatore pagina (per viste salvate). Es: 'manage_employees'
     * @param string $table_sel   Selettore CSS della tabella target (default '#data-table')
     * @param array  $opts        Opzioni:
     *                            - 'title' (string)  Etichetta sopra il filtro (default: nessuna)
     *                            - 'export_filename' (string)  Base name file export
     *                            - 'shared_views' (bool)  Permette viste condivise tra utenti (default false)
     *                            - 'compact' (bool)  Modalità compatta senza padding (default false)
     */
    public static function render(
        string $page_name,
        string $table_sel = '#data-table',
        array $opts = []
    ): void {
        $title           = $opts['title']           ?? '';
        $export_filename = $opts['export_filename'] ?? $page_name;
        $shared_views    = !empty($opts['shared_views']);
        $compact         = !empty($opts['compact']);

        // Componenti unici per istanze multiple nella stessa pagina
        $instance_id = 'lf_' . substr(md5($page_name . $table_sel . microtime(true)), 0, 8);

        $page_name_safe = htmlspecialchars($page_name, ENT_QUOTES);
        $table_sel_safe = htmlspecialchars($table_sel, ENT_QUOTES);
        $filename_safe  = htmlspecialchars(preg_replace('/[^a-z0-9_-]/i', '_', $export_filename), ENT_QUOTES);
        $title_safe     = htmlspecialchars($title, ENT_QUOTES);

        // Stampa CSS+JS una volta sola per richiesta
        if (!self::$assets_printed) {
            self::$assets_printed = true;
            self::printAssets();
        }

        $pad = $compact ? '8px 10px' : '12px 14px';
        ?>
<div class="lf-toolbar" id="<?= $instance_id ?>" style="padding:<?= $pad ?>"
     data-page="<?= $page_name_safe ?>"
     data-table="<?= $table_sel_safe ?>"
     data-filename="<?= $filename_safe ?>"
     data-shared-views="<?= $shared_views ? '1' : '0' ?>">

  <?php if ($title): ?>
  <div class="lf-title"><?= $title_safe ?></div>
  <?php endif; ?>

  <div class="lf-row-main">
    <div class="lf-search-wrap">
      <i class="fa-solid fa-magnifying-glass lf-search-icon"></i>
      <input type="text" class="lf-search" placeholder="Cerca in tutta la tabella…"
             autocomplete="off" spellcheck="false">
      <button type="button" class="lf-clear-btn" title="Pulisci ricerca">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <button type="button" class="lf-btn lf-btn-advanced" title="Mostra/nascondi filtri avanzati">
      <i class="fa-solid fa-filter"></i> Filtri
      <span class="lf-active-count" style="display:none">0</span>
    </button>

    <div class="lf-views-wrap">
      <button type="button" class="lf-btn lf-btn-views" title="Viste salvate">
        <i class="fa-solid fa-bookmark"></i> Viste
      </button>
      <div class="lf-views-menu" style="display:none">
        <div class="lf-views-list">
          <div class="lf-views-empty">Nessuna vista salvata.</div>
        </div>
        <div class="lf-views-actions">
          <button type="button" class="lf-btn lf-btn-save-view">
            <i class="fa-solid fa-floppy-disk"></i> Salva vista corrente
          </button>
        </div>
      </div>
    </div>

    <div class="lf-export-wrap">
      <button type="button" class="lf-btn lf-btn-export" title="Esporta dati filtrati">
        <i class="fa-solid fa-download"></i> Esporta
      </button>
      <div class="lf-export-menu" style="display:none">
        <div class="lf-export-scope">
          <label title="Esporta l'intero elenco, ignorando i filtri applicati">
            <input type="checkbox" class="lf-export-all"> Tutti i record (ignora i filtri)
          </label>
        </div>
        <button type="button" data-format="csv">
          <i class="fa-solid fa-file-csv"></i> CSV (.csv)
        </button>
        <button type="button" data-format="xlsx">
          <i class="fa-solid fa-file-excel"></i> Excel (.xlsx)
        </button>
        <button type="button" data-format="pdf">
          <i class="fa-solid fa-file-pdf"></i> PDF (stampa)
        </button>
        <button type="button" data-format="docx">
          <i class="fa-solid fa-file-word"></i> Word (.docx)
        </button>
      </div>
    </div>

    <div class="lf-counter">
      <span class="lf-rows-shown">0</span> <span class="lf-rows-label">righe</span>
    </div>
  </div>

  <!-- Pannello filtri avanzati (auto-popolato dalle colonne) -->
  <div class="lf-advanced-panel" style="display:none">
    <div class="lf-advanced-grid">
      <!-- Popolato dinamicamente al primo render -->
    </div>
    <div class="lf-advanced-actions">
      <button type="button" class="lf-btn lf-btn-reset">
        <i class="fa-solid fa-rotate-left"></i> Reset filtri
      </button>
    </div>
  </div>
</div>
        <?php
    }

    /**
     * Stampa CSS + JS del componente (una volta per richiesta).
     */
    private static function printAssets(): void
    {
        ?>
<style>
/* ═══════════════════════════════════════════════════════════════════════
   ListFilter v1.7.19 — Stile filtri + viste + export
   ═══════════════════════════════════════════════════════════════════════ */
.lf-toolbar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 12px;
    font-family: inherit;
    font-size: 13px;
    color: #1e293b;
    position: relative;
}
.lf-toolbar * { box-sizing: border-box; }
.lf-title {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 8px;
}
.lf-row-main {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.lf-search-wrap {
    position: relative;
    flex: 1 1 280px;
    min-width: 240px;
}
.lf-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 13px;
    pointer-events: none;
}
.lf-search {
    width: 100%;
    padding: 8px 36px 8px 36px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
}
.lf-search:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.12);
}
.lf-clear-btn {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: 0;
    color: #94a3b8;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 4px;
    display: none;
}
.lf-clear-btn:hover { background: #f1f5f9; color: #475569; }
.lf-clear-btn.visible { display: block; }

.lf-btn {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    font-family: inherit;
    transition: all .15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.lf-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
.lf-btn.active { background: #3b82f6; color: white; border-color: #2563eb; }
.lf-btn-save-view { width: 100%; justify-content: center; }

.lf-active-count {
    background: #ef4444;
    color: white;
    border-radius: 9px;
    padding: 1px 7px;
    font-size: 10px;
    font-weight: 700;
    margin-left: 4px;
}

.lf-views-wrap, .lf-export-wrap { position: relative; }
.lf-views-menu, .lf-export-menu {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 4px;
    background: white;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba(15,23,42,.12);
    min-width: 240px;
    z-index: 100;
    overflow: hidden;
}
.lf-export-menu button {
    display: block;
    width: 100%;
    padding: 10px 14px;
    background: white;
    border: 0;
    text-align: left;
    cursor: pointer;
    font-size: 13px;
    color: #1e293b;
    font-family: inherit;
    border-bottom: 1px solid #f1f5f9;
}
.lf-export-menu button:last-child { border-bottom: 0; }
.lf-export-menu button:hover { background: #f1f5f9; }
.lf-export-scope {
  padding: 9px 12px;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
  font-size: 12px;
  color: #334155;
  white-space: nowrap;
}
.lf-export-scope label { display: flex; align-items: center; gap: 7px; cursor: pointer; }
.lf-export-scope input { margin: 0; cursor: pointer; }
.lf-export-menu button i {
    width: 18px;
    margin-right: 8px;
    color: #475569;
}
.lf-export-menu button[data-format="csv"]  i { color: #16a34a; }
.lf-export-menu button[data-format="xlsx"] i { color: #047857; }
.lf-export-menu button[data-format="pdf"]  i { color: #dc2626; }
.lf-export-menu button[data-format="docx"] i { color: #2563eb; }

.lf-views-list {
    max-height: 240px;
    overflow-y: auto;
}
.lf-views-empty {
    padding: 12px 14px;
    color: #94a3b8;
    font-size: 12px;
    font-style: italic;
    text-align: center;
}
.lf-view-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
}
.lf-view-item:hover { background: #f1f5f9; }
.lf-view-item-name {
    font-weight: 600;
    font-size: 13px;
    color: #1e293b;
}
.lf-view-item-meta {
    font-size: 10px;
    color: #94a3b8;
}
.lf-view-item-delete {
    background: transparent;
    border: 0;
    color: #cbd5e1;
    padding: 4px 8px;
    cursor: pointer;
    border-radius: 4px;
}
.lf-view-item-delete:hover { color: #dc2626; background: #fee2e2; }
.lf-views-actions {
    border-top: 1px solid #e2e8f0;
    padding: 8px;
    background: #f8fafc;
}

.lf-counter {
    margin-left: auto;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    background: #f1f5f9;
    border-radius: 12px;
}
.lf-rows-shown { color: #1e293b; font-weight: 700; }

.lf-advanced-panel {
    margin-top: 10px;
    padding: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
}
.lf-advanced-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
}
.lf-adv-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.lf-adv-field label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.lf-adv-field input,
.lf-adv-field select {
    padding: 6px 9px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
    background: white;
    font-family: inherit;
    outline: none;
}
.lf-adv-field input:focus,
.lf-adv-field select:focus { border-color: #3b82f6; }
.lf-advanced-actions {
    border-top: 1px solid #e2e8f0;
    padding-top: 10px;
    display: flex;
    gap: 8px;
}

/* Modale "salva vista" */
.lf-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    z-index: 9999;
    display: flex; align-items: center; justify-content: center;
}
.lf-modal {
    background: white;
    border-radius: 8px;
    padding: 24px;
    min-width: 360px;
    max-width: 500px;
    box-shadow: 0 24px 48px rgba(15,23,42,.24);
}
.lf-modal h3 {
    margin: 0 0 14px 0;
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
}
.lf-modal input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    margin-bottom: 12px;
    font-family: inherit;
}
.lf-modal-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

/* Riga nascosta dal filtro */
.lf-hidden { display: none !important; }

/* Toast */
.lf-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 16px;
    border-radius: 6px;
    color: white;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(15,23,42,.24);
    z-index: 10000;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s, transform .2s;
    pointer-events: none;
}
.lf-toast.visible { opacity: 1; transform: translateY(0); }
.lf-toast-success { background: #16a34a; }
.lf-toast-error   { background: #dc2626; }
.lf-toast-info    { background: #2563eb; }

@media (max-width: 700px) {
    .lf-row-main { gap: 6px; }
    .lf-counter { width: 100%; text-align: right; margin-top: 6px; }
    .lf-search-wrap { flex: 1 1 100%; }
}
</style>
<script>
/* ═══════════════════════════════════════════════════════════════════════
   ListFilter — JS lato client
   Filtra, salva viste, esporta in CSV/XLSX/PDF/DOCX
   ═══════════════════════════════════════════════════════════════════════ */
(function() {
    if (window.__ListFilterLoaded) return;
    window.__ListFilterLoaded = true;

    // ── Inizializza tutte le istanze .lf-toolbar nel DOM ──
    function initAll() {
        document.querySelectorAll('.lf-toolbar').forEach(initInstance);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    function initInstance(root) {
        if (root.__inited) return;
        root.__inited = true;

        const pageName = root.dataset.page;
        const tableSel = root.dataset.table;
        const filename = root.dataset.filename;
        const sharedViews = root.dataset.sharedViews === '1';
        const table = document.querySelector(tableSel);

        if (!table) {
            console.warn('[ListFilter] tabella non trovata:', tableSel);
            return;
        }

        const $search = root.querySelector('.lf-search');
        const $clear = root.querySelector('.lf-clear-btn');
        const $btnAdv = root.querySelector('.lf-btn-advanced');
        const $advPanel = root.querySelector('.lf-advanced-panel');
        const $advGrid = root.querySelector('.lf-advanced-grid');
        const $btnReset = root.querySelector('.lf-btn-reset');
        const $btnViews = root.querySelector('.lf-btn-views');
        const $viewsMenu = root.querySelector('.lf-views-menu');
        const $viewsList = root.querySelector('.lf-views-list');
        const $btnSaveView = root.querySelector('.lf-btn-save-view');
        const $btnExport = root.querySelector('.lf-btn-export');
        const $exportMenu = root.querySelector('.lf-export-menu');
        const $rowsShown = root.querySelector('.lf-rows-shown');
        const $activeCount = root.querySelector('.lf-active-count');

        // ── Costruisco lista filtri per colonna dalle <th> ──
        const headers = Array.from(table.querySelectorAll('thead th'))
                              .map(th => (th.textContent || '').trim());
        const colCount = headers.length;
        const filters = {};   // {colIndex: {type, value}}

        // Crea un input per ogni colonna nel pannello filtri avanzati
        headers.forEach((header, idx) => {
            if (!header || header === '' || header === '#') return; // skip vuote/numeri

            const wrap = document.createElement('div');
            wrap.className = 'lf-adv-field';

            const lbl = document.createElement('label');
            lbl.textContent = header;
            wrap.appendChild(lbl);

            const inp = document.createElement('input');
            inp.type = 'text';
            inp.placeholder = 'Contiene…';
            inp.dataset.colIndex = idx;
            inp.addEventListener('input', () => {
                filters[idx] = { type: 'contains', value: inp.value };
                applyFilters();
                updateActiveCount();
            });
            wrap.appendChild(inp);

            $advGrid.appendChild(wrap);
        });

        // ── Accesso alle righe ──────────────────────────────────────────────
        // v1.8.44: quando la tabella è gestita da DataTables, il DOM contiene
        // soltanto le righe della pagina corrente (tipicamente 25): le altre
        // sono state staccate. Interrogare `tbody tr` restituirebbe quindi una
        // frazione dei dati, troncando sia i filtri sia l'export. L'API di
        // DataTables conserva invece tutti i nodi, anche quelli fuori pagina.
        function getRowNodes() {
            try {
                const jq = window.jQuery || window.$;
                if (jq && jq.fn && jq.fn.dataTable && jq.fn.dataTable.isDataTable(table)) {
                    return jq(table).DataTable().rows().nodes().toArray();
                }
            } catch (e) { /* DataTables assente o non inizializzato: fallback al DOM */ }
            return Array.from(table.querySelectorAll('tbody tr'));
        }

        // ── Applica filtri al DOM ──
        function applyFilters() {
            const q = ($search.value || '').toLowerCase().trim();
            const rows = getRowNodes();
            let visible = 0;

            rows.forEach(row => {
                // Skip righe "no-data" / placeholder
                if (row.classList.contains('lf-noskip')) return;

                const cells = row.querySelectorAll('td');
                const rowText = row.textContent.toLowerCase();

                // 1) Search testuale globale
                let pass = q === '' || rowText.indexOf(q) !== -1;

                // 2) Filtri per colonna
                if (pass) {
                    for (const colIdx in filters) {
                        const f = filters[colIdx];
                        if (!f || !f.value) continue;
                        const cell = cells[colIdx];
                        if (!cell) continue;
                        const cellText = cell.textContent.toLowerCase();
                        if (cellText.indexOf(f.value.toLowerCase()) === -1) {
                            pass = false; break;
                        }
                    }
                }

                row.classList.toggle('lf-hidden', !pass);
                if (pass) visible++;
            });

            $rowsShown.textContent = visible.toLocaleString('it-IT');
            const lbl = root.querySelector('.lf-rows-label');
            if (lbl) lbl.textContent = (visible === 1 ? 'riga' : 'righe');
        }

        function updateActiveCount() {
            let n = 0;
            for (const k in filters) if (filters[k] && filters[k].value) n++;
            if (n > 0) {
                $activeCount.style.display = 'inline-block';
                $activeCount.textContent = n;
            } else {
                $activeCount.style.display = 'none';
            }
        }

        // ── Search bar ──
        $search.addEventListener('input', () => {
            $clear.classList.toggle('visible', $search.value !== '');
            applyFilters();
        });
        $clear.addEventListener('click', () => {
            $search.value = '';
            $clear.classList.remove('visible');
            applyFilters();
            $search.focus();
        });

        // ── Pannello filtri avanzati ──
        $btnAdv.addEventListener('click', () => {
            const visible = $advPanel.style.display !== 'none';
            $advPanel.style.display = visible ? 'none' : 'block';
            $btnAdv.classList.toggle('active', !visible);
        });

        // ── Reset filtri ──
        $btnReset.addEventListener('click', () => {
            $search.value = '';
            $clear.classList.remove('visible');
            $advGrid.querySelectorAll('input').forEach(i => i.value = '');
            for (const k in filters) delete filters[k];
            applyFilters();
            updateActiveCount();
            toast('Filtri azzerati', 'info');
        });

        // ── Menu viste ──
        $btnViews.addEventListener('click', e => {
            e.stopPropagation();
            const open = $viewsMenu.style.display !== 'none';
            $viewsMenu.style.display = open ? 'none' : 'block';
            $exportMenu.style.display = 'none';
            if (!open) loadSavedViews();
        });

        // ── Menu export ──
        $btnExport.addEventListener('click', e => {
            e.stopPropagation();
            const open = $exportMenu.style.display !== 'none';
            $exportMenu.style.display = open ? 'none' : 'block';
            $viewsMenu.style.display = 'none';
        });

        document.addEventListener('click', e => {
            if (!root.contains(e.target)) {
                $viewsMenu.style.display = 'none';
                $exportMenu.style.display = 'none';
            }
        });

        // ── Salva vista corrente ──
        $btnSaveView.addEventListener('click', () => {
            const name = promptModal('Nome vista', 'es. "Dipendenti attivi"');
            if (!name) return;
            const filtersData = {
                search: $search.value,
                columns: filters,
            };
            fetch('saved_views_api.php?action=save', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken()},
                body: JSON.stringify({
                    page_name: pageName,
                    name: name,
                    filters: filtersData,
                    is_shared: sharedViews ? 1 : 0,
                }),
            })
            .then(r => r.json())
            .then(j => {
                if (j.success) {
                    toast('Vista "' + name + '" salvata.', 'success');
                    loadSavedViews();
                } else {
                    toast(j.error || 'Errore salvataggio vista', 'error');
                }
            })
            .catch(e => toast('Errore: ' + e.message, 'error'));
        });

        function loadSavedViews() {
            $viewsList.innerHTML = '<div class="lf-views-empty">Caricamento…</div>';
            fetch('saved_views_api.php?action=list&page_name=' + encodeURIComponent(pageName))
                .then(r => r.json())
                .then(j => {
                    if (!j.success) {
                        $viewsList.innerHTML = '<div class="lf-views-empty">Errore: ' + (j.error || '') + '</div>';
                        return;
                    }
                    if (!j.views || j.views.length === 0) {
                        $viewsList.innerHTML = '<div class="lf-views-empty">Nessuna vista salvata.</div>';
                        return;
                    }
                    $viewsList.innerHTML = '';
                    j.views.forEach(v => {
                        const item = document.createElement('div');
                        item.className = 'lf-view-item';
                        item.innerHTML =
                            '<div>' +
                              '<div class="lf-view-item-name">' + escapeHtml(v.name) + '</div>' +
                              '<div class="lf-view-item-meta">' + (v.is_shared ? '👥 condivisa' : '🔒 privata') +
                                ' · ' + formatDate(v.updated_at) + '</div>' +
                            '</div>' +
                            '<button class="lf-view-item-delete" title="Elimina"><i class="fa-solid fa-trash"></i></button>';
                        item.querySelector('.lf-view-item-name').parentElement.addEventListener('click', () => {
                            applyView(v);
                            $viewsMenu.style.display = 'none';
                        });
                        item.querySelector('.lf-view-item-delete').addEventListener('click', e => {
                            e.stopPropagation();
                            if (!confirm('Eliminare la vista "' + v.name + '"?')) return;
                            fetch('saved_views_api.php?action=delete&id=' + v.id, {method: 'POST', headers: {'X-CSRF-Token': getCsrfToken()}})
                                .then(r => r.json())
                                .then(j => {
                                    if (j.success) {
                                        toast('Vista eliminata', 'success');
                                        loadSavedViews();
                                    } else {
                                        toast(j.error || 'Errore', 'error');
                                    }
                                });
                        });
                        $viewsList.appendChild(item);
                    });
                })
                .catch(e => {
                    $viewsList.innerHTML = '<div class="lf-views-empty">Errore: ' + e.message + '</div>';
                });
        }

        function applyView(v) {
            const f = v.filters || {};
            $search.value = f.search || '';
            $clear.classList.toggle('visible', $search.value !== '');
            for (const k in filters) delete filters[k];
            if (f.columns) {
                Object.assign(filters, f.columns);
                $advGrid.querySelectorAll('input').forEach(inp => {
                    const idx = inp.dataset.colIndex;
                    inp.value = (filters[idx] && filters[idx].value) ? filters[idx].value : '';
                });
            }
            applyFilters();
            updateActiveCount();
            toast('Vista applicata: ' + v.name, 'success');
        }

        // ── Export ──
        $exportMenu.querySelectorAll('button[data-format]').forEach(btn => {
            btn.addEventListener('click', () => {
                const format = btn.dataset.format;
                $exportMenu.style.display = 'none';
                exportData(format);
            });
        });

        function exportData(format) {
            // v1.8.43: l'ambito dipende dalla casella "Tutti i record". Quando è
            // spuntata si esporta l'intero elenco a prescindere dai filtri
            // applicati, utile per estrazioni complete dell'anagrafica.
            const all = !!(root.querySelector('.lf-export-all')?.checked);
            const data = collectVisibleData(all);
            if (data.rows.length === 0) {
                toast('Nessuna riga da esportare', 'error');
                return;
            }
            const fname = filename + (all ? '_completo' : '') + '_' + new Date().toISOString().slice(0, 10);

            if (format === 'csv')      exportCsv(data, fname);
            else if (format === 'xlsx') exportXlsx(data, fname);
            else if (format === 'pdf')  exportPdf(data, fname);
            else if (format === 'docx') exportDocx(data, fname);

            toast(data.rows.length.toLocaleString('it-IT') + (all ? ' righe (elenco completo)' : ' righe filtrate'), 'success');
        }

        function collectVisibleData(includeAll) {
            const headers = Array.from(table.querySelectorAll('thead th'))
                                  .map(th => (th.textContent || '').trim());
            const rows = [];
            // v1.8.44: getRowNodes() restituisce l'intero insieme di righe anche
            // con DataTables attivo, dove il DOM ne espone solo una pagina.
            getRowNodes().forEach(row => {
                if (!includeAll && row.classList.contains('lf-hidden')) return;
                if (row.classList.contains('lf-noskip')) return;
                const cells = Array.from(row.querySelectorAll('td')).map(td => {
                    // Estrae solo testo, rimuove HTML/icone interattive
                    const clone = td.cloneNode(true);
                    clone.querySelectorAll('button, .btn, i.fa, .fa-solid, .fa-regular, .fa-brands')
                         .forEach(e => e.remove());
                    return (clone.textContent || '').trim().replace(/\s+/g, ' ');
                });
                rows.push(cells);
            });
            return { headers, rows };
        }

        function exportCsv(data, fname) {
            const escape = v => {
                v = String(v ?? '');
                if (/[",\n;]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
                return v;
            };
            const sep = ';';   // Excel italiano usa ; come separatore
            const lines = [data.headers.map(escape).join(sep)];
            data.rows.forEach(r => lines.push(r.map(escape).join(sep)));
            const bom = '\uFEFF';   // UTF-8 BOM per accenti
            downloadBlob(bom + lines.join('\r\n'), fname + '.csv', 'text/csv;charset=utf-8');
        }

        function exportXlsx(data, fname) {
            // Genera XLSX minimal (Office Open XML)
            // Struttura: ZIP con [Content_Types].xml, _rels, xl/workbook.xml, xl/worksheets/sheet1.xml, xl/sharedStrings.xml
            // Per semplicità, uso un endpoint server-side
            postExport('xlsx', data, fname);
        }

        function exportDocx(data, fname) {
            // DOCX minimal generato server-side
            postExport('docx', data, fname);
        }

        // v1.7.37: helper per leggere il CSRF token dal meta tag
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        }

        function postExport(format, data, fname) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'saved_views_api.php?action=export';
            form.target = '_self';

            const fields = {
                format: format,
                filename: fname,
                title: pageName,
                payload: JSON.stringify({ headers: data.headers, rows: data.rows }),
                _csrf: getCsrfToken(),  // v1.7.37: CSRF token per Csrf::verify()
            };
            for (const k in fields) {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = k;
                inp.value = fields[k];
                form.appendChild(inp);
            }
            document.body.appendChild(form);
            form.submit();
            setTimeout(() => document.body.removeChild(form), 1500);
        }

        function exportPdf(data, fname) {
            // PDF tramite finestra di stampa del browser
            const win = window.open('', '_blank');
            const style = `
                <style>
                    body { font-family: -apple-system, Segoe UI, sans-serif; padding: 20px; color: #1e293b; }
                    h1 { font-size: 16px; margin: 0 0 12px 0; color: #003399; border-bottom: 2px solid #003399; padding-bottom: 6px; }
                    .meta { font-size: 10px; color: #64748b; margin-bottom: 14px; }
                    table { width: 100%; border-collapse: collapse; font-size: 10px; }
                    th { background: #003399; color: white; padding: 6px 8px; text-align: left; }
                    td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
                    tr:nth-child(even) td { background: #f8fafc; }
                    @media print { body { padding: 0; } }
                </style>
            `;
            const date = new Date().toLocaleString('it-IT');
            let html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escapeHtml(fname)}</title>${style}</head><body>`;
            html += `<h1>${escapeHtml(pageName.replace(/_/g, ' ').toUpperCase())}</h1>`;
            html += `<div class="meta">Esportato il ${date} · ${data.rows.length} righe · PortalManager</div>`;
            html += '<table><thead><tr>';
            data.headers.forEach(h => html += '<th>' + escapeHtml(h) + '</th>');
            html += '</tr></thead><tbody>';
            data.rows.forEach(r => {
                html += '<tr>';
                r.forEach(c => html += '<td>' + escapeHtml(c) + '</td>');
                html += '</tr>';
            });
            html += '</tbody></table></body></html>';
            win.document.write(html);
            win.document.close();
            setTimeout(() => win.print(), 400);
        }

        function downloadBlob(content, fname, mime) {
            const blob = new Blob([content], { type: mime });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fname;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 500);
        }

        // ── Helpers UI ──
        function toast(msg, kind) {
            const t = document.createElement('div');
            t.className = 'lf-toast lf-toast-' + (kind || 'info');
            t.textContent = msg;
            document.body.appendChild(t);
            requestAnimationFrame(() => t.classList.add('visible'));
            setTimeout(() => {
                t.classList.remove('visible');
                setTimeout(() => document.body.removeChild(t), 300);
            }, 2500);
        }

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);
        }

        function formatDate(s) {
            if (!s) return '';
            try { return new Date(s).toLocaleDateString('it-IT'); }
            catch (e) { return s; }
        }

        function promptModal(title, placeholder) {
            // Per ora uso prompt() semplice — può essere migliorato con modale custom
            return prompt(title, '');
        }

        // ── Render iniziale: aggiorna contatore righe ──
        applyFilters();
    }
})();
</script>
        <?php
    }
}
