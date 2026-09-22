# Release Checklist — PortalManager v1.8.97

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.96.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.97` |
| `cron_cost_consolidate.php` | ROOT, **NUOVO** | OK |
| `app/CostModel.php` | invariato, incluso per dipendenza | OK |
| `app/FormulaEval.php` | invariato, incluso per dipendenza | OK |
| `app/Version.php` | modificato | OK |
| 9 ROOT + 9 in `app/` | invariati da v1.8.96 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.97**
- [x] `cron_cost_consolidate.php` **non** in `Router::PAGES`: CLI, protetto da
      `PHP_SAPI !== 'cli'` → 403

## 2. Il campo giusto

- [x] `TotCostoTab` è calcolato da `CostModel` e **non scritto in nessuna
      tabella**: la diagnosi del cliente era corretta
- [x] `employees.valore_tabp` **NON è** TotCostoTab: è il valore del buono pasto
      (46,48 €), un ingresso del calcolo
- [x] Chiave nel risultato: `calc.tot_costo_tab`

## 3. Consolidamento verificato

| Dipendente | TotCostoTab | Giorno | Ora |
|---|---|---|---|
| Orru' Antonello | 107.298,84 | 487,72 | **60,97** |
| Macinai Alessandro | 62.950,94 | 286,14 | 35,77 |
| Anzidei Massimo | 50.430,10 | 229,23 | 28,65 |
| Fossati Damiano | 36.273,55 | 164,88 | 20,61 |

- [x] **189 consolidati**, 97 senza dati economici, 0 errori
- [x] Formula verificata: `107.298,84 / 220 / 8 = 60,97` — **OK**
- [x] Riusa `CostModel`, non riscrive la formula: due implementazioni divergono

## 4. Perché consolidare

- [x] Il calcolo usa i parametri **correnti**: applicarlo agli interventi passati
      farebbe **riscrivere i margini di commesse chiuse** da un aumento di
      stipendio
- [x] Un report stampato il mese scorso deve restare riproducibile
- [x] Ogni intervento usa il costo **dell'anno in cui è stato svolto**

## 5. Ripiego sull'esercizio precedente

| Origine | Interventi |
|---|---|
| consolidato | 18.224 |
| **stimato da 2025** | **12.319** |
| non disponibile | 36.071 |

- [x] Come richiesto: si mantiene l'esercizio consolidato fino all'aggiornamento
      del dato dell'anno successivo
- [x] Solo esercizi **precedenti**: un costo 2026 sul 2025 sarebbe un anacronismo
- [x] `costo_origine` qualifica sempre il valore; `copertura_pct` lo aggrega
- [x] Senza ripiego, l'anno corrente resterebbe senza redditività per mesi

## 6. Parametri per esercizio

- [x] `cm_cost_year_params`: una riga per anno, **220 predefinito**
- [x] Per anno e non globale: cambiare 220 → 254 sposterebbe **tutti** i costi
      storici del 15%
- [x] `is_closed` protegge gli esercizi chiusi; `--force` per forzare

## 7. Costo aziendale e costo di vendita

| Grandezza | Valore |
|---|---|
| Ricavo (702 commesse a ricavo) | 27.593.628 € |
| Costo aziendale | **3.140.390 €** |
| Costo di vendita | **7.935.863 €** |

- [x] **Affiancati, non sostituiti**: rispondono a domande diverse
- [x] `scarto_costo_pct` misura la divergenza — 2,5× sui dati aggregati
- [x] Un margine unico avrebbe eliminato l'informazione per cui il calcolo serviva

## 8. Difetto intercettato in collaudo

```php
$tot = $res['tot_costo_tab'] ?? null;   // sempre null
```

- [x] `compute()` restituisce `['used','calc','errors']`: i derivati stanno sotto
      **`calc`**
- [x] **Guasto silenzioso**: 286 dipendenti, 286 «senza dati», zero eccezioni
- [x] Visibile solo confrontando il risultato con l'attesa
- [x] Stessa categoria di `Router::hiddenParams` e del filtro `IN` su viste
      annidate: codice valido, tipo giusto, contenuto sbagliato

## 9. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 12 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 12 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 12 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c97` fresco | 640 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c97` | 640 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c97` | 640 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **630 → 640**
- [x] Metodi `CostModel` verificati: `economics()`, `compute()` — entrambi
      esistenti
- [x] Chiavi del risultato verificate: `tot_costo_tab`, `totale_pre_overhead`,
      `valore_fte`, `totale_fte_ca`

## 10. Aperto

- **La copertura è al 22,5%**: 97 dipendenti su 286 non hanno dati economici, e i
  36.071 interventi «non disponibile» restano senza costo. I margini a costo reale
  sono indicativi finché la copertura non sale.
- **Un solo esercizio disponibile**: `hr_employee_economics` ha solo il 2025. Il
  ripiego funziona ma non è ancora stato provato con due esercizi consecutivi.
- **La pagina della redditività non è inclusa**: le viste sono pronte e
  interrogabili da SQL.
- Restano gli aperti precedenti: presidi senza pagina, giornate di copertura da
  definire, riepiloghi cadenzati non implementati.
