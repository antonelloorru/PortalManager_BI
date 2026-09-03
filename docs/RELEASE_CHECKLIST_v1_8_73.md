# Release Checklist — PortalManager v1.8.73

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.72.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.73` |
| `app/SyncDatasets.php` | **modificato** (14 dataset) | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.72 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.73**

## 2. Diagnosi

- [x] La pagina DGB legge `dgb_forms_activity` e `dgb_forms_activity_operator`;
      la scheda commessa legge `cm_intervention_reports`
- [x] Le due tabelle DGB **non erano fra i target** dei 12 dataset: scritte solo
      da `DgbSync`, import separato non richiamato da *Sincronizza tutto*
- [x] Verifica sul backup del 19/08/2026:

| Mese | Attività DGB | Rapporti |
|---|---|---|
| 2026-06 | 2.877 | 2.443 |
| 2026-07 | 2.449 | 2.392 |
| **2026-08** | **348** | **714** |

- [x] Il controllo dell'utente sulla singola commessa usava la base **aggiornata**:
      due letture di tabelle diverse, entrambe coerenti con sé stesse

## 3. Correzione

| Dataset | Sorgente | Destinazione | Colonne |
|---|---|---|---|
| Attività DGB | `forms_activity` | `dgb_forms_activity` | **33** |
| Ore per operatore su attività DGB | `forms_activity_has_dgb_operator` | `dgb_forms_activity_operator` | **18** |

- [x] Entrambe validate sul dump con `LIMIT 0`: **mappatura allineata**
- [x] Dataset totali: **12 → 14**
- [x] Ordine: `attivita_dgb` dopo `commesse` (aggancio per `id_contract`),
      `allocazioni_dgb` dopo `attivita_dgb` (aggancio per `id_activity`)
- [x] Correzione **strutturale** e non rilancio dell'import: due procedure con
      due tempi di esecuzione divergono, è questione di quando non di se

## 4. `import_batch_id` mancante, di nuovo

- [x] Le due tabelle DGB ne erano prive: la sincronizzazione sarebbe fallita con
      *Unknown column*, come nella v1.8.64 su cinque tabelle
- [x] Aggiunta esplicitamente dalla migration, con indice
- [x] **Limite rilevato**: `v_cm_sync_schema_check` ha l'elenco **cablato** e non
      conosceva queste tabelle, perché non erano ancora destinazioni. Un controllo
      con elenco fisso protegge solo da ciò che sa già

## 5. Controllo di allineamento

- [x] `v_cm_allineamento_dgb` confronta mese per mese le due basi
- [x] `UNION` dei mesi da entrambe: un `LEFT JOIN` da una sola nasconderebbe i
      mesi presenti solo nell'altra, cioè il caso da rilevare
- [x] `pct_copertura` come rapporto e non differenza: 400 righe di scarto
      significano cose diverse su un mese da 2.500 e su uno da 700
- [x] Dichiarato che il 100% non è atteso: un'attività può coinvolgere più
      operatori, le due tabelle contano entità diverse
- [x] Il segnale è **un mese recente con copertura vicina a zero**

## 6. Difetto intercettato in collaudo

- [x] Un `;` in un commento SQL spezzava lo statement per lo **splitter naive**
      (1 errore su 9) mentre il tokenizer reale passava
- [x] Individuato dal controllo `grep -c '^[[:space:]]*--.*;'` e dal RUN3
- [x] Corretto: entrambi gli splitter ora a **err=0**

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c73` fresco | 509 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c73` | 509 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c73` | 509 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **502 → 509**

## 8. Nota di metodo

Il difetto non produceva errori: produceva **due verità**, ciascuna coerente con
sé stessa. La scheda commessa mostrava dati aggiornati, la pagina DGB no, ed
entrambe leggevano correttamente la propria tabella.

Quando due viste dello stesso fatto divergono, la domanda non è quale sia
sbagliata ma **da dove ciascuna prende i dati**.

## 9. Aperto

- `DgbSync` resta nel codice ma non è più necessario: le sue tabelle sono ora
  alimentate dai dataset. Vale la pena decidere se rimuoverlo, come fatto con
  `CommesseSync` nella v1.8.63.
- L'elenco cablato in `v_cm_sync_schema_check` andrebbe derivato da
  `SyncDatasets::keys()` invece che scritto a mano, così un nuovo dataset entra
  nel controllo automaticamente.
- Restano aperti: le tre divisioni senza commesse, e l'export immagine dei
  grafici.
