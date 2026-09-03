# Release Checklist — PortalManager v1.9.8

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.7.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.8` |
| `header.php` | ROOT, **modificato** | OK |
| `service_desk.php` | ROOT, **modificato** | OK |
| `it_service.php` | ROOT, **modificato** | OK |
| `dir_report.php` | ROOT, **modificato** | OK |
| `assets/pm-filters.css` | **NUOVO** | n/a |
| `app/DirModel.php` | **+ 2 filtri** | OK |
| `app/ItServiceModel.php` | **+ 2 filtri** | OK |
| `app/Version.php` | modificato | OK |
| 27 file restanti | invariati da v1.9.7 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.8**
- [x] **`assets/pm-filters.css` va copiato**: senza, i pannelli funzionano ma
      senza stile

## 2. Estrarre invece di copiare

- [x] Gli stili erano in un `<style>` dentro `manage_projects.php`
- [x] Copiarli in quattro viste avrebbe creato **quattro copie divergenti**
- [x] `manage_projects.php` **non modificato**: continua con i propri stili
      inline, ora ridondanti ma innocui. Rimuoverli significherebbe toccare la
      vista di riferimento in una release dedicata alle altre

## 3. I campi aggiunti

| Vista | Campi |
|---|---|
| Report direzionale | ricerca libera, cliente, azienda esecutrice |
| Relazione IT | ricerca libera, cliente |
| Service Desk | **classe di gestione** |

- [x] `gest` **esisteva nel modello dalla v1.8.84** e non nel pannello: usabile
      solo modificando l'URL
- [x] Secondo caso dopo la v1.9.6 di funzione presente e irraggiungibile

## 4. Il controllo pannello ↔ modello

- [x] Ogni `name` del pannello confrontato con le chiavi di `normFilters()`
- [x] Un campo ignorato dal modello produce un filtro che **si compila, si invia,
      ricarica la pagina e non cambia nulla**: nessun errore, solo un utente
      convinto di aver ristretto i dati
- [x] Stessa famiglia del filtro `IN` su viste annidate (v1.8.88) e della lettura
      alla radice invece che sotto `calc` (v1.9.7)
- [x] Esito: **19 campi su tre viste, 0 non riconosciuti**

## 5. Scelte di interfaccia

- [x] **`<details>` e non JavaScript**: funziona a script disattivato, nessuno
      stato da sincronizzare
- [x] **`open` deciso lato server** dai filtri attivi: un pannello chiuso che
      nasconde filtri fa credere di guardare tutti i dati
- [x] **Griglia `auto-fit`** invece di quattro colonne fisse: le viste hanno da 6
      a 10 filtri, quattro colonne avrebbero lasciato buchi o righe monche
- [x] **Menu multipli con altezza propria**: quella predefinita mostra una riga
      sola e chi fa clic su una seconda voce perde la prima senza capire perché
- [x] **Ricerca su tre colonne** — codice, denominazione, cliente: i tre modi in
      cui una commessa viene nominata. Su tutte le colonne avrebbe restituito
      corrispondenze su note interne e identificativi tecnici

## 6. QA sui dati reali

| Verifica | Esito |
|---|---|
| Coerenza pannello ↔ modello | **19 campi, 0 KO** |
| Direzionale: ricerca «USL» | 56 su 605 |
| Direzionale: cliente «TOSCANA» | 66 |
| Filtri combinati | q=USL + tutte → 114 |
| Service Desk: classe di gestione | filtro attivo |
| `php -l` su tutti i file | OK |
| Migration RUN1/RUN2 | 4 stmt, **err=0**, idempotente |
| `;` nei commenti SQL | **0** |

## 7. Aperto

- **`workload_overview.php` e `dgb_activities.php` non sono stati uniformati**:
  hanno rispettivamente 12 e 20 filtri, con logiche proprie (schede, granularità,
  modalità). Uniformarli richiede di riprogettarne il raggruppamento, non solo di
  cambiare il contenitore.
- **Verifica a schermo non eseguita**: il comportamento è verificato sul codice e
  sui dati, non aprendo il portale in un browser.
- **`manage_projects.php` conserva gli stili inline**: ridondanti ma innocui.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`
  invece di `margin_total`, pagine mancanti per presidi e redditività, riepiloghi
  cadenzati.
