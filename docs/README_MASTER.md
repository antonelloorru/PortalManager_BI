# PortalManager v1.9.34 — Master Pack

Rigenerazione completa delle pagine **Service Desk** e **Relazione di Servizio IT**
con tutte le modifiche cumulative:

- Filtri multi-select con barra di ricerca (click semplice, senza Ctrl)
- Etichette anagrafiche `Cognome Nome`
- Nuova sezione "Dettaglio per Commessa" (in pagina e nel report di stampa)
- Vista SQL `v_rsi_dettaglio_commessa` con `riga_formattata`

## File del gestionale interessati

| File | Ruolo | Cosa patcha v1.9.34 |
|------|-------|---------------------|
| `it_service.php` | View Relazione di Servizio IT | Include pm-ui-boost, `class="pm-ms"` sui select, chiamata dettaglioCommessa(), sezione HTML |
| `app/ItServiceModel.php` | Model | Aggiunge metodo `dettaglioCommessa()` |
| `app/it_service_print.php` | Vista stampa | Chiamata metodo + sezione stampa |
| `service_desk.php` | View Service Desk | Include pm-ui-boost, `class="pm-ms"` sui select |
| `assets/js/pm-ui-boost.js` | Componente UI | Nuovo (copiato in webroot) |
| `assets/css/pm-ui-boost.css` | Skin componente | Nuovo (copiato in webroot) |

Fornito anche in bundle:
- `relazione_servizio_it.php` — pagina standalone alternativa (se preferisci non modificare `it_service.php`)
- `tools/pm_diagnostic.php` — diagnostica browser (Super Admin) per verificare lo stato

## Installazione — 3 comandi

```powershell
:: 1) Patch master (idempotente, backup automatico, lint post-patch, copia asset)
P:\xampp\php\php.exe pm_v1_9_34\patches\apply_v1_9_34_master.php P:\xampp\htdocs\portalmanager

:: 2) Migration
mysql -uroot portalmanager < pm_v1_9_34\sql\migration_v1_9_34.sql

:: 3) Ricarica Apache/OPcache
net stop Apache2.4 & net start Apache2.4
```

## Modalità selettive dell'installer

- `--dry-run` — mostra cosa modificherebbe senza scrivere nulla:
  ```powershell
  P:\xampp\php\php.exe pm_v1_9_34\patches\apply_v1_9_34_master.php P:\xampp\htdocs\portalmanager --dry-run
  ```
- `--only=it_service` — patcha solo la Relazione di Servizio IT
- `--only=service_desk` — patcha solo il Service Desk

## Output atteso

```
══════════════════════════════════════════════════════════════════
  PortalManager v1.9.34 — Master Patch (Service Desk + IT Service)
══════════════════════════════════════════════════════════════════
→ Root: P:\xampp\htdocs\portalmanager
→ Modalità: APPLICA

── it_service.php + ItServiceModel.php + it_service_print.php ──
OK — ItServiceModel.php (1 modifiche) · backup: ItServiceModel.php.bak_v1_9_34_YYYYMMDD_HHMMSS
OK — it_service.php (5 modifiche) · backup: it_service.php.bak_v1_9_34_YYYYMMDD_HHMMSS
OK — it_service_print.php (2 modifiche) · backup: it_service_print.php.bak_v1_9_34_YYYYMMDD_HHMMSS

── service_desk.php ─────────────────────────────────────────────
OK — service_desk.php (N modifiche) · backup: service_desk.php.bak_v1_9_34_YYYYMMDD_HHMMSS

── Copia asset pm-ui-boost ────────────────────────────────────────
✔ P:\xampp\htdocs\portalmanager\assets\js\pm-ui-boost.js
✔ P:\xampp\htdocs\portalmanager\assets\css\pm-ui-boost.css

══════════════════════════════════════════════════════════════════
[OK] 4 file patchati.
```

## Verifica browser

Ricarica `Ctrl+F5` entrambe le pagine e verifica:

### Service Desk
- Ogni `<select>` (Componente del team, Coda, Livello, Classe di gestione) ha barra di ricerca in alto
- Click singolo per selezionare (nessun Ctrl)
- Etichette operatori mostrate come "Cognome Nome"

### Relazione di Servizio IT (`it_service.php`)
- Multi-select con search anche su Linea, Codice linea, Settore, Azienda, Incaricato, Sede, Modalità, Fascia, Durata, Raggruppa per, Natura
- Etichette incaricati "Cognome Nome"
- **Nuova sezione in fondo alla pagina**: "Dettaglio per Commessa" con gruppi per contratto DGB con header formato `CODICE | INSTALLAZIONE | CLIENTE | DESCR` e tabella righe (Data, Operatore, Ticket, Fascia, Regime, Ore, Costo contratto, TotCostoTab)
- Stessa sezione appare anche cliccando "Report generale/personale" (stampa)

## Rollback

Ogni file ha un backup timestampato. Ripristino:
```powershell
for %F in (
  P:\xampp\htdocs\portalmanager\it_service.php
  P:\xampp\htdocs\portalmanager\app\ItServiceModel.php
  P:\xampp\htdocs\portalmanager\app\it_service_print.php
  P:\xampp\htdocs\portalmanager\service_desk.php
) do @for /f "delims=" %B in ('dir /b /o-d "%F.bak_v1_9_34_*"') do @copy /Y "%~dpF%B" "%F" && goto :nextF
:nextF
del  P:\xampp\htdocs\portalmanager\assets\js\pm-ui-boost.js
del  P:\xampp\htdocs\portalmanager\assets\css\pm-ui-boost.css
mysql -uroot portalmanager -e "DROP VIEW IF EXISTS v_rsi_dettaglio_commessa; UPDATE app_settings SET setting_value='1.9.33' WHERE setting_key='app_version'; DELETE FROM pm_migration_sql WHERE version='1.9.34';"
net stop Apache2.4 & net start Apache2.4
```

## Contenuto pacchetto

```
pm_v1_9_34/
├── VERSION                                     1.9.34
├── README_MASTER.md                            questa guida
├── patches/apply_v1_9_34_master.php            patch idempotente 4 file + copia asset
├── assets/js/pm-ui-boost.js                    componente drop-in
├── assets/css/pm-ui-boost.css                  skin light/dark
├── sql/migration_v1_9_34.sql                   vista + bump versione
├── relazione_servizio_it.php                   pagina standalone alternativa
├── tools/pm_diagnostic.php                     diagnostica browser
└── docs/                                       (docs supplementari)
```

## Sicurezza applicata

- Marker `PM_V1_9_34_APPLIED` in ogni file per prevenire doppia applicazione
- Backup timestampato prima di ogni scrittura (nome: `<file>.bak_v1_9_34_YYYYMMDD_HHMMSS`)
- Scrittura atomica via `rename()` da file temporaneo
- `php -l` post-patch: se fallisce, ripristino automatico dal backup, exit code 2
- Metodo `dettaglioCommessa()` incapsulato in try/catch: se le tabelle DGB
  non ci sono ritorna `[]` invece di crashare la pagina
- Nessuna modifica al backend SQL delle query esistenti (aggiunte solo,
  no ALTER destruttivi)

## Test in laboratorio

- **4 file patchati** su fixture (view + model + print + service_desk)
- **10 modifiche totali** inserite (include, class, chiamate, sezioni)
- `php -l` clean su tutti e 4 dopo la patch
- **RUN2 idempotente**: marker rilevato, `[SKIP] già patchato` per ogni file
- Migration SQL RUN1 + RUN2 idempotenti sulla vista
- Vista `v_rsi_dettaglio_commessa.riga_formattata` produce:
  ```
  WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24 |  | TCK-2026-00001 | Senior | 4.00 | 160.00 € | 180.00 €
  ```

## Se qualcosa non torna

Apri nel browser (come Super Admin):
```
http://<host>/portalmanager/tools/pm_diagnostic.php
```
Copia prima `pm_v1_9_34/tools/pm_diagnostic.php` nella cartella `tools/`
del gestionale. Mostra: vista presente/righe, tabelle DGB, migration log,
app_version, permessi RBAC correlati, file candidati.
