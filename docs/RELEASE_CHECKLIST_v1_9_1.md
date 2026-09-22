# Release Checklist — PortalManager v1.9.1

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.0.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.1` |
| `app/Version.php` | modificato | OK |
| 31 file restanti | invariati da v1.9.0 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.1**
- [x] **Nessuna vista esistente modificata**

## 2. Lo scostamento misurato sui dati reali

| Formula | Commesse | Attuale | Corretto | Scarto |
|---|---|---|---|---|
| A | 6 | 310.058 | 307.799 | −2.259 |
| B | **775** | 15.361.134 | 15.361.134 | **0** |
| C | 143 | 2.548.444 | 9.787.503 | **+7.239.059** |
| D | 168 | 4.299.557 | 2.419.780 | **−1.879.777** |
| **TOTALE** | **1.092** | **22.519.194** | **27.876.216** | **+5.357.022** |

- [x] Scostamento del **23,8%** sul margine complessivo
- [x] Copertura **98,9%**: 12 commesse su regola predefinita

## 3. Concentrazione

| Tipo | Scarto | % |
|---|---|---|
| Presidio (50 cmm) | **+5.811.117** | +154% |
| Contr. Servizi Scalare (168) | **−1.879.778** | −44% |
| Servizio Gestito SOC (16) | +1.397.042 | +119% |

- [x] Due direzioni opposte su tipi diversi: non si compensano per caso

## 4. Perché era invisibile

- [x] **775 su 1.092 (71%)** usano la formula B, che coincide con il calcolo
      applicato finora a tutte
- [x] Un errore sul 29% dei casi, concentrato su tre tipi, produce un totale
      sbagliato del 24% **senza anomalie su nessuna commessa "normale"**
- [x] Non deducibile dai dati: serviva la fonte esterna

## 5. Aggiungere invece di sostituire

- [x] `margine` e `margine_attuale` **affiancate**, con `scarto_formula`
- [x] Le viste dei pannelli **non toccate**: sostituirle avrebbe mosso ogni
      cruscotto da un giorno all'altro e reso irriconciliabili i report stampati
- [x] Il passaggio resta una decisione da prendere guardando i numeri

## 6. Scelte tecniche

- [x] `CASE` **ripetuto** in tre colonne invece di una vista intermedia: le viste
      annidate su questo database hanno già mentito (v1.8.88, `IN` dava 2 righe
      su 520). La ripetizione è verbosa e verificabile
- [x] `margine_pct` usa il **consuntivato** come denominatore sulla formula D:
      rapportarlo al plafond darebbe un numero decrescente a redditività costante
- [x] `costi_a_zero` modifica **un addendo dentro** la formula, non seleziona una
      formula diversa: trattarla come tale avrebbe dato 12 casi invece di 4×3
      composti

## 7. Quality Assurance SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1 | `pm_real` (1.092 commesse reali) | 6 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_real` | 6 stmt, **err=0** |
| Migration RUN3 | `pm_real` | 6 stmt, **err=0** |
| Coda del consolidato RUN1 | `pm_real` | 4 stmt, **err=0** |
| Coda RUN2 (idempotenza) | `pm_real` | 4 stmt, **err=0** |

- [x] **Quadratura vista ↔ misura manuale**: 22.519.194 / 27.876.216 — identica
- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `php -l` su tutti i file: OK

## 8. Assunzione dichiarata

**Gli storni**: il documento dice «costi consuntivati e storni», e ho usato
`actual_cost` assumendo che siano già compresi nel consuntivo consolidato.

Se fossero esposti separatamente, `- storni` va aggiunto alle formule B, C e D —
non ad A, che non usa i costi. È un'assunzione che, se sbagliata, sposta il
risultato in modo sistematico.

## 9. Aperto

- **Il passaggio ai nuovi valori** nei pannelli: modifica circoscritta, da fare
  dopo la verifica dello scostamento.
- Restano gli aperti precedenti: pagine mancanti per presidi e redditività a costo
  reale, riepiloghi cadenzati, copertura dei costi consolidati al 22,5%.
