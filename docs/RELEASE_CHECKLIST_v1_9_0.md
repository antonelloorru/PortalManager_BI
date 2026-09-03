# Release Checklist — PortalManager v1.9.0

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.99.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.0` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.8.99 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.0**

## 2. Conferma incrociata su 20 codici

| Gestionale | Formula | Gestionale | Base |
|---|---|---|---|
| `F` | A | `ZC` | costi_a_zero |
| `V` | B | `CR` | fascia |
| `D` | C | `FC` | full_cost |
| `T` | D | | |

- [x] **Zero divergenze** fra deduzione dal documento e codifica del gestionale,
      su formule e su basi di costo
- [x] `T` = «Time material», il nome che il documento usa per WTS-CSS: le due
      fonti si spiegano a vicenda
- [x] Codici originali **conservati** in `margin_type` e `tm_cost_type`:
      un'interpretazione senza l'originale accanto è indistinguibile da
      un'invenzione

## 3. Una supposizione smentita dai dati

- [x] `is_dynamic` interpretava «Dinamico» come «commessa aperta di volta in
      volta»: **sbagliato**
- [x] I sei tipi hanno codici stabili: `NV_SC`, `NV_DT`, `NV_EVENTI`, `NV_FI`,
      `NV_GC`, `NV_GS`
- [x] Ora risolvono per **corrispondenza diretta**; il ripiego resta come rete
      per linee `NV_*` future
- [x] Averla resa esplicita — colonna dedicata, origine dichiarata nella vista —
      ha reso la correzione un `UPDATE` invece di una riscrittura

## 4. `NV_SC` è un'eccezione vera

- [x] `tm_cost_type = CR` — fascia — contro `FC` di tutte le altre `NV_`
- [x] **Entrambe le fonti** dicono la stessa cosa: è una scelta aziendale, non un
      refuso
- [x] Su una fonte sola sarebbe stato ragionevole uniformare, e sbagliato

## 5. Tipi in dismissione

- [x] 15 tipi con `report_model = 'ELIMINARE E CONVERTIRE'` nel gestionale
- [x] Sette registrati con `is_legacy = 1`, `is_active = 0`
- [x] **Esclusi dalla risoluzione**: una commessa su linea `NIS-*` ricade sulla
      predefinita ed è dichiarata, invece di ricevere una regola abbandonata
- [x] Registrati comunque: documenta che il tipo esiste e perché non è attivo

## 6. Il controllo permanente

```sql
SELECT * FROM v_cm_calc_mappa;
```

| `margin_type` | Formula | Base | Righe | Esito |
|---|---|---|---|---|
| D | C | full_cost | 10 | coerente |
| V | B | fascia | 6 | coerente |
| D | C | fascia | 1 | coerente |
| D | C | costi_a_zero | 1 | coerente |
| F | A | costi_a_zero | 1 | coerente |
| T | D | fascia | 1 | coerente |

- [x] **20 righe attive su 20 coerenti**
- [x] Il valore è alla prossima modifica: una formula cambiata senza il codice
      verrebbe segnalata subito, invece di emergere dal primo margine sbagliato

## 7. Quality Assurance SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_t100` | 28 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_t100` | 28 stmt, **err=0** |
| Migration RUN3 | `pm_t100` | 28 stmt, **err=0** |
| Coda v1.9.0 del consolidato, RUN1 | `pm_coda2` | 26 stmt, **err=0** |
| Coda v1.9.0, RUN2 (idempotenza) | `pm_coda2` | 26 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Risoluzione verificata su 9 commesse: linee dirette, ex-dinamici, legacy,
      linea sconosciuta
- [x] `php -l` su tutti i file: OK

## 8. Nota sul dump

Il dump caricato è del **gestionale** (3,7 GB), esplorato in streaming senza
estrarlo — lo spazio disponibile era inferiore alla dimensione decompressa.

Contiene `forms_contract_type` con 33 tipi di contratto, che è esattamente la
tabella corrispondente al documento.

## 9. Aperto — il passo successivo

**Le viste economiche non applicano ancora queste formule.** La tabella di
riferimento è ora confermata da due fonti indipendenti; riscrivere
`v_cm_redditivita_commessa` è il passo successivo.

Serve però il dump del **portale**: quello caricato contiene i tipi di contratto,
ma le commesse con i loro valori — `value_todate`, `actual_cost`, gli storni —
stanno nel database del portale.

Con quello si può misurare **quanto cambiano i margini** applicando le formule
corrette, in particolare sulle 12 linee di formula C dove oggi il consuntivo viene
sottratto invece che sommato.
