# Release Checklist — PortalManager v1.9.16

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.15.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.16` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.15 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] **Nessun file applicativo modificato**: cambiano le viste, non le pagine
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.16**

## 2. Il dataset che stavo per duplicare

- [x] `cm_contract_rates` conteneva già **35.335 righe su 1.173 commesse**
- [x] Il mio dataset `tariffe` **sovrascriveva la chiave esistente senza errore**:
      `all()` restituisce un array, una chiave ripetuta vince sulla precedente
- [x] Conseguenza evitata: il portale avrebbe smesso di sincronizzare la tabella
      vera popolandone una nuova — **due listini, uno fermo**
- [x] Trovato verificando le chiavi dopo l'inserimento. **Non era un controllo
      pianificato**: il controllo andrebbe reso sistematico

## 3. Quarta volta lo stesso schema

- v1.9.1 formula dedotta · v1.9.2 `margin_total` ricostruito · v1.9.12 stati per
  plausibilità · v1.9.16 tabella duplicata
- [x] Sempre: **cercare dove il problema si presenta invece di dove il dato sta**

## 4. La deduzione era la moda

| Fascia C | Dedotto | Media reale | Massima |
|---|---|---|---|
| H | 100,00 | 99,53 | 100,00 |
| HD | 87,50 | 85,95 | 100,00 |
| D | 81,25 | 80,13 | 100,00 |

- [x] **704 contratti su 1.142** hanno C/H = 100,00: giusta su 704, sbagliata su
      438
- [x] `tariffa_origine` distingue `contratto` da `dedotta da template`

## 5. La fascia si legge

- [x] `dgb_forms_activity.id_activitytype`: 1=A … 6=X
- [x] Prima la deducevo dall'orario: un'attività fascia B veniva calcolata C o D
- [x] `COALESCE(fascia_letta, deduzione)` con `fascia_origine` dichiarato

## 6. `CEH` fuori dal calcolo

- [x] Non nella mappatura fornita, ma **3.794 righe valorizzate**
- [x] `rate_nature` = C, le altre R. Il join filtra su **R**
- [x] Includerla avrebbe sommato costi e ricavi: totale plausibile, **senza
      segnali**

## 7. QA sui dati reali

| Verifica | Esito |
|---|---|
| Copertura fascia C | 59,5%–65,1% secondo l'unità |
| ANT_3633: tariffe reali | 100,00 / 87,50 / 81,25 |
| Riproduzione del template | **101 h, 8.795,00 €** |
| Moduli valorizzati dal contratto | **23 su 23** |
| Moduli senza tariffa | **0** |
| Migration RUN1/RUN2/RUN3 | 11 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | 9 stmt, **err=0** |
| `;` nei commenti SQL | **0** (uno intercettato) |

## 8. Aperto

- **Viste e stampe Generale/Personale**: la richiesta ne chiedeva anche
  l'implementazione. Questa release ha assorbito interamente la logica costi —
  che era il prerequisito — e le stampe restano da completare.
- **Valorizzazione a costo (`CEH`)**: la struttura la accoglie, non è stata
  aggiunta perché non richiesta.
- **`fascia_origine`**: sui dati reali va verificato quanti moduli hanno l'attività
  collegata. Se «dedotta da orario» prevale, le fasce sono in gran parte supposte.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12, giorni vuoti
  sull'asse giornaliero, `workload_overview` e `dgb_activities` non uniformati.
