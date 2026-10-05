# CHANGELOG — v1.10.01
Progetti PRJ — fase 4 di 6: elenco, scheda progetto, collegamento alla commessa SP, parametri.

## Funzionalità
- **Commesse / Progetti**: schede «Commesse SP» (elenco invariato) e «Progetti PRJ» (`?view=prj`).
  - Colonne: codice PRJ, nome, cliente, società esecutrice, stato, commessa SP collegata (link), scenario di riferimento, FTE, costo aziendale totale, % canone, ultimo calcolo.
  - Filtri: ricerca, stato (multipla), società, cliente, collegato/non collegato, periodo.
  - Azioni: Nuovo, Clona, Collega/Scollega, export XLSX con foglio Filtri.
  - Accessibile con il solo permesso «Progetti PRJ (elenco)», anche a chi non vede le commesse SP.
- **Scheda progetto PRJ** (`prj_dashboard.php`), 8 tab:
  - **Anagrafica**: codice in sola lettura, cliente, società, tipo, stato, responsabile, gara/CIG, date, note; effort di offerta per centro di costo.
  - **Collegamento commessa**: commessa attuale con i valori sincronizzati, ricerca per codice commessa, suggerimenti con punteggio, collega/sostituisci/scollega con motivo, storico.
  - **Gara**: durata e fasi, base d'asta per anno (con aggiunta di anni), tariffario Uncommitted, documenti e fonti.
  - **Servizi & Tecnologie**: servizi modificabili (modalità, interventi, fuori orario, H24, avvio), tecnologie per servizio collegate al catalogo.
  - **Asset & Volumi**: carico da ticket per servizio (ticket, ore, FTE da ticket, con uplift, allocati, scostamento), ore medie per ticket, produttività, volumi per gruppo e anno, mapping gruppo→servizio, asset.
  - **Profili**: N. minimo, FTE, RAL min/ideale, anni, lingue, flag H24 e nearshore, certificazioni; candidati interni e professionisti assegnati con % e periodo.
  - **Costi**: per scenario, costo per profilo (RAL di riferimento e di zona, oneri, H24, costo per FTE, €/giorno, strutturale), composizione del costo, strutturale per FTE, overhead.
  - **Scenari**: confronto affiancato di tutti gli scenari (15 indicatori, ultimo calcolo), creazione, modifica, clonazione, eliminazione, scenario di riferimento ★, «Calcola e salva» con data as-of, andamento per anno, personalizzazioni per profilo (FTE, remoto, H24, escluso).
- **Modifiche versionate**: ogni griglia chiede la decorrenza. Una data successiva apre una nuova versione; la stessa data della versione vigente fa una rettifica. Ogni campo è tracciato in EntityChangeLog.
- **Parametri dimensionamento** (`prj_parameters.php`, menu Gestione Commesse dopo «Fasce costo orario»):
  - parametri globali, zone, nearshore, dotazioni, costi di sede, overhead;
  - modifica versionata, aggiunta, dismissione e storico delle versioni;
  - costo strutturale per FTE per zona.
- **Sincronizzazione del gestionale**: al termine registra i PRJ rimasti senza commessa (evento «orfano da sync» + log) e collega in automatico i PRJ il cui codice è nel campo «commerciale» della commessa.

## Integrazioni
- `app/PrjLink.php`, `app/PrjUi.php`, `app/prj_list.php`, `api_prj.php` (ricerca commesse SP, JSON).
- `PrjRepo`: creazione, clonazione completa, rettifica in place, nuove righe versionate, dismissione, storico.
- `access_control.php` (api_prj.php, vista PRJ), `manage_projects.php`, `app/MenuManager.php`, `app/Router.php`, `app/PermissionCatalog.php`, `app/CommesseSync.php`, `assets/pm-filters.css` (schede a link).

## QA
- `tools/verify_v1_10_01.php`: 30 OK, 0 KO (Dump 19.80 e DB di test). `tools/verify_v1_10_00.php`: 43 OK invariato.
- Test con login reale come Super Admin: 8 tab, salvataggi, collegamento, calc run, override, clonazioni, creazione, parametri, export, nessun warning PHP.
- Test con login reale come Finance: elenco e scheda in sola lettura, parametri modificabili, API di collegamento negata (403).
- Migration RUN1/RUN2 err=0; `php -l` su tutti i file.
