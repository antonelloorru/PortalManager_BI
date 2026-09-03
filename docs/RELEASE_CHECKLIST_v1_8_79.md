# Release Checklist — PortalManager v1.8.79

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.78.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.79` |
| `app/SyncDatasets.php` | **modificato** — deduplica su 2 dataset | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 5 in `app/` | invariati da v1.8.78 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.79**

## 2. Diagnosi

- [x] Errore: `1062 Duplicate entry '71416-2627' for key uq_dfao_activity_operator`
- [x] **Il vincolo ha funzionato**: respinge un duplicato invece di accettarlo in
      silenzio, come accadeva prima della v1.8.78
- [x] Causa: chiave del dataset = `id` (tecnica), chiave del fatto = coppia
      (attività, operatore) (naturale). Due id per la stessa coppia → due INSERT
- [x] Il vincolo non ha creato il problema: lo ha reso visibile

## 3. Correzione su DUE dataset

| Dataset | Destinazione | Deduplica |
|---|---|---|
| `allocazioni_dgb` | `dgb_forms_activity_operator` | **sì** |
| `rapporti` | `cm_intervention_reports` | **sì** |

- [x] Il secondo legge la **stessa tabella**: senza correzione avrebbe continuato
      a generare due rapporti per intervento, e le ore sarebbero tornate a
      raddoppiare dopo la prima sincronizzazione
- [x] Regola già formulata in v1.8.63: due dataset sulla stessa tabella
      condividono i difetti della sorgente
- [x] `MAX(id)` come criterio, **identico** a quello della v1.8.78 sul portale:
      due criteri diversi divergerebbero al primo caso di valori non identici

## 4. Difetto aggiuntivo trovato correggendo

- [x] In `rapporti`, il conteggio operatori per attività — usato per **ripartire
      i valori economici** — usava `COUNT(*)` invece di
      `COUNT(DISTINCT id_operator)`
- [x] Con un duplicato la quota veniva divisa per 3 anziché per 2: ogni operatore
      riceveva meno del dovuto
- [x] Il vincolo non lo avrebbe mai segnalato: non produce righe in più, solo
      valori più bassi

## 5. Scelte di progetto

- [x] Deduplica **nella query**, non gestione della collisione in `DatasetSync`:
      intercettare l'errore 1062 nasconderebbe la differenza fra duplicato atteso
      e collisione imprevista
- [x] Vincolo **rimosso e riapplicato** attorno alla pulizia: una sincronizzazione
      fallita a metà può lasciare uno stato che la DELETE deve poter correggere
- [x] `v_cm_rapporti_doppi_attivita`: la grana `source_uid` non intercetta il
      caso, perché due allocazioni producono due codici diversi — stesso limite
      della v1.8.70 con i codici `DGB-<id>`

## 6. QA

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_c78` completo | 12 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_c78` | 12 stmt, **err=0** |
| Migration RUN3 naive | `pm_c78` | 12 stmt, **err=0** |
| Consolidato RUN1 | `pm_c79` fresco | 552 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | `pm_c79` | 552 stmt, **err=0** |
| Consolidato RUN3 tokenizer | `pm_c79` | 552 stmt, **err=0** |

Sul dump reale (`pm_n`):

| Verifica | Esito |
|---|---|
| Allocazioni | 71.217 |
| Ore | 344.375,5 |
| `v_dgb_allocazioni_duplicate` | **0** |
| Vincolo attivo | **sì** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **542 → 552**

## 7. Nota di metodo

Un errore comparso **dopo** una correzione non significa che la correzione fosse
sbagliata. Qui il vincolo faceva esattamente il suo mestiere: rendere visibile
un'incoerenza che prima passava.

La correzione giusta non era rimuovere il vincolo ma togliere l'incoerenza a
monte — e cercarla in tutti i dataset che leggono quella tabella, non solo in
quello che ha segnalato.

## 8. Aperto

- Se la sorgente continuasse a produrre coppie duplicate, varrebbe la pena
  chiedersi **perché** il gestionale le generi: la deduplica le nasconde
  correttamente al portale, ma il dato a monte resta ambiguo.
- Il conteggio corretto degli operatori farà **aumentare leggermente** i valori
  economici per operatore sulle attività che avevano duplicati: atteso e
  documentato.
