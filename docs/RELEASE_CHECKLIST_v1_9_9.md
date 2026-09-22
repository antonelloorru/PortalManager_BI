# Release Checklist — PortalManager v1.9.9

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.8.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.9` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **6 metodi corretti** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.9.8 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.9**

## 2. L'audit

- [x] 24 metodi confrontati con due domande: riceve `$f`, ha un vincolo temporale
- [x] **6 difettosi**, 18 corretti
- [x] Concentrati nei metodi aggiunti in momenti diversi, ciascuno con la propria
      convenzione

## 3. Il caso più insidioso

- [x] `trend()` riceveva i filtri e **li usava a metà**: dodici mesi a ritroso
      dalla data finale, ignorando quella iniziale
- [x] Il grafico **non corrispondeva agli indicatori sopra di esso**
- [x] Un metodo che ignora del tutto i filtri si nota; uno che ne usa uno su due
      produce un grafico plausibile e sbagliato

## 4. Il denominatore delle code

- [x] `codeDettaglio()` rapportava al totale **storico** della coda
- [x] Ora al totale **nello stesso periodo**: un trimestre diviso per tre anni
      darebbe percentuali minuscole, scambiate per un difetto di calcolo

## 5. Un difetto preesistente scoperto dall'audit

- [x] `operativita()` ordinava per **`ordina`, colonna che la vista non ha**
- [x] La query falliva, il `try/catch` restituiva `[]`, e la tabella **«I
      componenti del Service Desk» era vuota** — da prima di questa release
- [x] **Nessun errore a schermo**: un `catch` che restituisce un elenco vuoto
      rende un guasto indistinguibile da un'assenza di dati
- [x] Chiave di ordinamento presa da `v_cm_nomi`
- [x] Verificato dopo la correzione: 2 componenti, 12,00 ore sull'anno

## 6. Le note aggiornate

- [x] «dati sull'intero archivio» → «periodo selezionato»
- [x] Una nota che descrive un comportamento superato è peggio di nessuna nota

## 7. QA — marzo 2026 contro anno 2026

| Misura | Marzo | Anno | Esito |
|---|---|---|---|
| Moduli | 2 | 3 | filtra |
| Ore | 7,0 | 12,0 | filtra |
| Assenze | 76,5 | 687,5 | filtra |
| Mesi di assenza | 1 | 9 | filtra |
| Righe andamento | 1 | 2 | filtra |
| Ore operatività | 7,0 | 12,0 | filtra |
| Mesi scheda | 1 | 2 | filtra |
| Periodo 1990 | 0 | | zero |

- [x] Nessuna misura dà **più** sul periodo stretto che sul largo
- [x] Le voci «uguale» sono conteggi di righe distinte che coincidono, non
      mancati filtri: `teamQuadro/ore` passa da 7,0 a 12,0 sugli stessi dati

## 8. QA — tutti i filtri

| Filtro | Valore | Ticket |
|---|---|---|
| base | — | 4 |
| `tec` | Sebastiano Chiarini | 2 |
| `level` | L1 / L2 | 3 / 0 |
| `gest` | risolto dal Service Desk | 3 |
| `queue` | Sistemi | 3 |

- [x] Tutti restringono, nessuno amplia

## 9. QA SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_real` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_real` | 4 stmt, **err=0** |

- [x] Un `;` in un commento SQL intercettato e corretto
- [x] Il `try/catch` di `operativita()` **nascondeva un guasto**: valutare di
      registrare l'eccezione invece di restituire `[]` in silenzio
- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file

## 10. Aperto

- **I dati di Service Desk restano vuoti nel dump**: il collaudo usa messaggi e
  moduli costruiti, poi rimossi. Le proporzioni sono verificate, i numeri reali si
  vedranno in produzione.
- **Verifica a schermo non eseguita**.
- Restano gli aperti precedenti: `workload_overview` e `dgb_activities` non
  uniformati, viste dei pannelli su `value_total - actual_cost`, pagine mancanti
  per presidi e redditività.
