# Release Checklist — PortalManager v1.9.12

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.11.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.12` |
| `app/SyncDatasets.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.11 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.12**

## 2. Il difetto

- [x] `a.status IN ('APPROVED','CLOSED','COMPLETED')` sensibile alla grafia
- [x] Il gestionale usa **due grafie**: `completed` 70.833, `closed` 5.675
- [x] I moduli di Bressi erano in **`assigned`**, assente dall'elenco in ogni
      grafia: **3 su 307 persi**
- [x] **`APPROVED` non esiste nel gestionale**: zero occorrenze. Un terzo
      dell'elenco filtrava su un valore inventato

## 3. La correzione

- [x] `UPPER(TRIM(COALESCE(a.status, '')))`: indifferente a grafia e spazi
- [x] Elenco di **11 stati** dichiarati dall'azienda
- [x] `TRIM` oltre a `UPPER`: un valore con spazi è indistinguibile a occhio, e
      un export che passa da un foglio di calcolo li aggiunge con facilità
- [x] Costo: l'indice su `status` non viene usato — irrilevante su una query che
      gira una volta al giorno

## 4. Il difetto strutturale

- [x] **`source_status` non esisteva**: il portale non conservava lo stato
- [x] Una riga scartata **non lasciava traccia**: né quante né perché
- [x] L'unico modo di accorgersene era confrontare un export con il portale, riga
      per riga — che è quello che il cliente ha fatto
- [x] `v_cm_ir_stati` e `v_cm_ir_copertura_tecnico` rendono il fenomeno
      misurabile dall'interno

## 5. Il parametro non governa la query

- [x] Dichiarato nel deployment: `sync_stati_moduli` documenta, la query filtra
- [x] Motivo: la query gira sul database del **gestionale**, dove `app_settings`
      non esiste
- [x] `v_cm_ir_stati.ammesso` rende visibile una divergenza invece di lasciarla
      latente

## 6. QA — 12 casi su 12

| Stato | Atteso | Esito |
|---|---|---|
| `assigned` | entra | **OK** — il caso di Bressi |
| `ASSIGNED` / `Assigned` | entra | OK |
| `completed` / `COMPLETED` | entra | OK |
| `closed` | entra | OK |
| `  OPEN  ` | entra | OK — spazi |
| `REJECTED` | entra | OK — scelta aziendale |
| `APPROVED` | scartato | OK — non esiste |
| `ARCHIVED` | scartato | OK |
| vuoto / `NULL` | scartato | OK |

- [x] Vecchio filtro: **4 prove su 12**; nuovo: **8**
- [x] Filtro estratto **dal sorgente vero**, non riscritto nel collaudo

## 7. QA SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1/RUN2/RUN3 | `pm_real` | 9 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | `pm_real` | 7 stmt, **err=0** |

- [x] Filtro nel codice = elenco dichiarato: **11 su 11**
- [x] Parametro = filtro nel codice: coincidono
- [x] `;` nei commenti SQL: **0**

## 8. Nota di metodo

Terza volta che si ripresenta lo stesso schema: la formula C dedotta dal documento
(v1.9.1), `margin_total` ricostruito senza verificare se esistesse (v1.9.2), e ora
un elenco di stati costruito per plausibilità.

**Dedurre invece di guardare.** Il correttivo non è un controllo automatico: è
verificare i valori che una colonna contiene davvero, prima di filtrarci sopra.

## 9. Aperto

- **La risincronizzazione è necessaria** e non automatica: i moduli mai importati
  entrano solo alla prossima esecuzione.
- **`OPEN` vale 327.892 righe** nel gestionale. Se dopo la risincronizzazione
  risultasse pesante, il portale starebbe contabilizzando lavoro non ancora
  svolto: `v_cm_ir_stati` serve a verificarlo sui dati invece che a priori.
- **`REJECTED` è incluso** per indicazione del cliente: contabilizza il lavoro
  respinto.
- **Il rollback non cancella i moduli** entrati con la nuova regola: la
  distinzione starebbe nella colonna che il rollback rimuove.
- **I moduli sono vuoti nel dump**: le viste sono verificate nella struttura, i
  numeri si vedranno dopo la risincronizzazione.
