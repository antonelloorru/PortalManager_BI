# PortalManager v1.9.30 — UI Multi-select + Label "Cognome Nome"

## Scope
Applicato a: **Relazione Servizi IT** (subito), **Service Desk** e
**Report Direzionale** (patch guidata in `patches/`).

## Task UI (Filtri)
Sostituite le select standard con un componente **multi-select con barra di
ricerca testuale**: click per selezionare/deselezionare (senza `Ctrl`), chip
di riepilogo, X per rimuovere. Nessuna dipendenza esterna (vanilla JS).

## Task Dati (Label Anagrafiche)
Etichette uniformate a **`Cognome Nome`** — regola nel helper
`PmFilters::person()` e SQL via `PmFilters::personSql()`.

## Contenuto
```
pm_v1_9_30/
├── VERSION                                      1.9.30
├── report_servizi_it.php                        pagina con multi-select attivi
├── assets/
│   ├── js/pm-multiselect.js                     componente drop-in (0 dip.)
│   └── css/pm-multiselect.css                   tema light + dark
├── app/PmFilters.php                            helper filtri + label
├── sql/migration_v1_9_30.sql                    vista aggiornata + log
├── patches/INTEGRAZIONE_dir_report_service_desk.md  guida integrazione
├── docs/README_v1_9_30.md
└── tools/verify_v1_9_30.php
```

## Installazione
```powershell
copy pm_v1_9_30\report_servizi_it.php   P:\xampp\htdocs\portalmanager\
robocopy pm_v1_9_30\assets\js   P:\xampp\htdocs\portalmanager\assets\js   pm-multiselect.js
robocopy pm_v1_9_30\assets\css  P:\xampp\htdocs\portalmanager\assets\css  pm-multiselect.css
copy pm_v1_9_30\app\PmFilters.php       P:\xampp\htdocs\portalmanager\app\
mysql -uroot portalmanager < pm_v1_9_30\sql\migration_v1_9_30.sql
net stop Apache2.4 ; net start Apache2.4
```

## Integrazione sugli altri 2 file
Segui `patches/INTEGRAZIONE_dir_report_service_desk.md`:
1) Aggiungi `<link>` CSS + `<script>` JS nella head.
2) `require_once __DIR__ . '/app/PmFilters.php';`
3) Modifica le `<select>` filtri in `<select multiple class="pm-ms"
   name="XXX[]">`.
4) Nei model/query: `PmFilters::ints($_GET['XXX'] ?? [])` +
   `PmFilters::inClause('col', $ids)`.
5) Sostituisci gli alias `CONCAT_WS(' ', first_name, last_name)` con
   `TRIM(CONCAT_WS(' ', last_name, first_name))`.

## Verifica funzionale
Dopo installazione:
```powershell
P:\xampp\php\php.exe pm_v1_9_30\tools\verify_v1_9_30.php --db=portalmanager --user=root --pass=
```
Attesi: 12 check verdi. In pagina:
- Il dropdown filtri mostra la **barra di ricerca**.
- Click su una voce **la aggiunge alla selezione senza Ctrl**.
- Le tabelle mostrano `Cognome Nome`.

## Vantaggi rispetto a Select2/CDN
- **Zero dipendenze** (nessun jQuery, nessun CDN esterno).
- **~5 KB** JS + ~2 KB CSS.
- Offline-friendly (funziona in DMZ senza egress).
- Tema **light/dark** automatico via `prefers-color-scheme`.
- Compatibile con qualsiasi form GET/POST già esistente
  (basta cambiare `name="xxx"` in `name="xxx[]"`).
