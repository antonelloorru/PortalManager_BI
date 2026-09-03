# Release Checklist — PortalManager v1.8.70

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.69.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.70` |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 6 in `app/` | invariati da v1.8.69 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.70**
- [x] **Release che ELIMINA DATI**: backup obbligatorio, dichiarato in ogni
      documento

## 2. Diagnosi sul database reale del cliente

Backup `PortalManager_db_portalmanager_20260818_150300.sql`, 412 MB.

| Gruppo | Righe | Ore | `dgb_activity_id` |
|---|---|---|---|
| codice reale | 69.042 | 344.395,50 | 68.079 |
| **`DGB-<id>`** | **67.786** | **328.629,00** | **0** |
| TOTALE | 136.828 | 673.024,50 | |

- [x] Consuntivo **quasi raddoppiato**: 328.629 ore in eccesso
- [x] Causa: formato `report_code` di `DgbSync` **precedente alla v1.8.51**
- [x] Il vincolo UNIQUE non poteva rilevarlo: due **codici diversi** per lo
      stesso fatto producono `source_uid` diversi

## 3. Prova che le righe sono ridondanti

| Verifica | Righe | Quota |
|---|---|---|
| gemello **esatto** (tecnico + data + ore) | **63.202** | 93,2% |
| rapporto reale stesso tecnico/giorno, ore per arrotondamento | 4.575 | 6,8% |
| realmente isolate | **9** | 0,01% |

- [x] Le 9 isolate hanno tutte `project_code` fittizio `DGB-<id>`
- [x] **Nessuna** riga `DGB-` ha `dgb_activity_id`, contro 68.079 su 69.042 delle
      reali: firma inequivocabile dell'import pre-v1.8.51
- [x] Verifica eseguita **prima** di scrivere la DELETE: senza, la cancellazione
      di 67.786 righe sarebbe stata un atto di fede

## 4. Criterio di eliminazione

- [x] `report_code LIKE 'DGB-%' AND dgb_activity_id IS NULL` — **due condizioni**
- [x] Il solo prefisso sarebbe bastato sui dati attuali, ma un import futuro
      potrebbe produrlo legittimamente con l'identificativo valorizzato
- [x] Il criterio descrive **il difetto**, non i suoi sintomi
- [x] Commesse `DGB-` rimosse solo se **senza rapporti collegati**: cancellarne
      una ancora referenziata lascerebbe rapporti orfani

## 5. QA — esecuzione sul database reale

| | Prima | Dopo |
|---|---|---|
| Rapporti | 136.828 | **69.042** |
| Ore | 673.024,50 | **344.395,50** |
| Commesse `DGB-` | presenti | **0** |
| `cm_cleanup_log` | — | `rows_removed=67786`, `hours_removed=328629.00` |

| Test | Esito |
|---|---|
| Migration sul DB reale | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | 9 stmt, **err=0**, 0 righe rimosse, log non duplicato |
| Migration RUN3 naive | 9 stmt, **err=0** |
| `v_cm_residui_import` dopo | **zero righe** |

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Consolidato RUN1 | splitter naive | `pm_c70` fresco | 496 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c70` | 496 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c70` | 496 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **489 → 496**

## 7. Tracciabilità

- [x] `cm_cleanup_log` conserva righe e ore prima e dopo: dopo una DELETE il dato
      non c'è più, e senza log non si potrebbe rispondere mesi dopo alla domanda
      «perché le ore sono calate»
- [x] `INSERT` condizionato a `> 0`: la seconda esecuzione non aggiunge una riga
      di zeri
- [x] `v_cm_residui_import` con `HAVING COUNT(*) > 0`: zero righe quando è tutto
      a posto

## 8. Nota di metodo

Il difetto non era nel vincolo né nella sincronizzazione attuale: entrambi
funzionano. Era **pregresso non ripulito** — la v1.8.51 aveva corretto il
comportamento futuro senza rimuovere ciò che il comportamento sbagliato aveva già
prodotto.

Vale la pena chiedersi, quando si corregge un difetto di importazione, che cosa
resti in tabella dalle esecuzioni precedenti.

## 9. Aperto

- **Le commesse restano 1.518** contro le ~1.062 del gestionale. Le `DGB-`
  fittizie sono state rimosse, ma la differenza persiste: vanno esaminati i
  prefissi anomali (`AZIE`, `CLIE`) che compaiono su 339 commesse. Non l'ho
  fatto in questa release perché richiede di capire da quale import provengano.
- Dopo la pulizia va rilanciata la sincronizzazione in **scrittura** per
  verificare che non reintroduca righe con il vecchio formato.
