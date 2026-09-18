# PortalManager v1.9.33 — Fix definitivo it_service.php

## File del gestionale interessati
- `it_service.php` (view) — pagina Relazione di Servizio IT
- `app/ItServiceModel.php` (model) — logica query
- `app/it_service_print.php` (view stampa)

## Cosa cambia
1. **Multi-select con search (senza Ctrl)** — include CSS/JS `pm-ui-boost` e
   aggiunge `class="pm-ms"` a TUTTI i `<select>` del form filtri.
   Componente vanilla JS, zero dipendenze esterne.
2. **Etichette Cognome Nome** — reorder client-side (pm-ui-boost). L'ORDER BY
   server-side usa già `incaricato_ordina` della vista `v_cm_it_servizio`
   quindi l'ordinamento delle option era già per cognome; il fix rende
   coerente anche il testo visualizzato.
3. **Nuova sezione "Dettaglio per Commessa"** — righe per contratto DGB
   con header formato:
   `WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24`
   e colonne Data, Operatore, Ticket, Fascia, Regime, Ore, Costo contratto (€),
   TotCostoTab (€).
4. **Anche in stampa** — la nuova sezione compare nel report XLSX/print.
5. Vista SQL `v_rsi_dettaglio_commessa` rifatta con `riga_formattata`
   pre-formattata (usabile da altre pagine/export).

## Contenuto pacchetto
```
pm_v1_9_33/
├── VERSION                                     1.9.33
├── assets/
│   ├── js/pm-ui-boost.js                       componente drop-in
│   └── css/pm-ui-boost.css                     skin light/dark
├── patches/
│   └── apply_v1_9_33.php                       auto-patch 3 file (view+model+print)
├── sql/migration_v1_9_33.sql                   vista + bump versione
├── docs/README_v1_9_33.md
```

## Installazione — 4 comandi
```powershell
:: 1) Asset in webroot
xcopy /Y pm_v1_9_33\assets\js\pm-ui-boost.js   P:\xampp\htdocs\portalmanager\assets\js\
xcopy /Y pm_v1_9_33\assets\css\pm-ui-boost.css P:\xampp\htdocs\portalmanager\assets\css\

:: 2) Patch chirurgica sui 3 file (idempotente, backup automatico)
P:\xampp\php\php.exe pm_v1_9_33\patches\apply_v1_9_33.php P:\xampp\htdocs\portalmanager

:: 3) Migration DB
mysql -uroot portalmanager < pm_v1_9_33\sql\migration_v1_9_33.sql

:: 4) OPcache reload
net stop Apache2.4 & net start Apache2.4
```

Output atteso dello step 2:
```
Modifiche:
  - MODEL: metodo dettaglioCommessa() aggiunto
  - VIEW: include CSS/JS/meta pm-ui-boost inserito dopo require_once('header.php')
  - VIEW: class="pm-ms" aggiunta a N <select>
  - VIEW: chiamata dettaglioCommessa() inserita dopo $gRic
  - VIEW: $dettCommessa=[] aggiunto al ramo catch
  - VIEW: sezione HTML 'Dettaglio per Commessa' inserita prima del footer
  - PRINT: chiamata dettaglioCommessa() inserita dopo $gOp
  - PRINT: sezione HTML stampa 'Dettaglio per Commessa' inserita prima di </body>
[OK] Patch v1.9.33 applicata. php -l pulito su entrambi i file.
```

## Verifica browser
Ricarica `Ctrl+F5` la pagina **Relazione di Servizio IT** (`it_service.php`):
1. **Filtri**: ogni tag di selezione ha ora una barra di ricerca. Click semplice per selezionare voci (senza Ctrl). Le voci compaiono come chip con X.
2. **Etichette operatori**: mostrate come "Cognome Nome" (heuristica client-side).
3. **Sezione "Dettaglio per Commessa"** in fondo: gruppi separati per contratto, ogni gruppo con header `WTS_3670 | WTS_CSS | CLIENTE | DESCR` e tabella righe.
4. **Report di stampa** (link "Report generale/personale"): stessa sezione presente.

## Rollback
```powershell
copy P:\xampp\htdocs\portalmanager\it_service.php.bak_v1_9_33_YYYYMMDD_HHMMSS               P:\xampp\htdocs\portalmanager\it_service.php
copy P:\xampp\htdocs\portalmanager\app\ItServiceModel.php.bak_v1_9_33_YYYYMMDD_HHMMSS       P:\xampp\htdocs\portalmanager\app\ItServiceModel.php
copy P:\xampp\htdocs\portalmanager\app\it_service_print.php.bak_v1_9_33_YYYYMMDD_HHMMSS     P:\xampp\htdocs\portalmanager\app\it_service_print.php
del  P:\xampp\htdocs\portalmanager\assets\js\pm-ui-boost.js
del  P:\xampp\htdocs\portalmanager\assets\css\pm-ui-boost.css
net stop Apache2.4 & net start Apache2.4
```

## Sicurezza
- Marker `PM_V1_9_33_APPLIED` in ciascun file per idempotenza.
- Backup timestampato prima di ogni scrittura.
- Scrittura atomica via `rename()`.
- `php -l` post-patch: se fallisce, ripristino automatico dal backup ed exit 2.
- Metodo `dettaglioCommessa()` incapsulato in try/catch: se le tabelle DGB
  non ci sono, ritorna `[]` invece di crashare la pagina.

## Test in laboratorio
- 8 modifiche applicate su 3 file di fixture, `php -l` clean
- RUN2 idempotente: marker rilevato, [NO-OP]
- Vista SQL `v_rsi_dettaglio_commessa.riga_formattata` produce esattamente:
  `WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24 |  | TCK-2026-00001 | Senior | 4.00 | 160.00 € | 180.00 €`
