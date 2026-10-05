# CHANGELOG v1.10.04 — Ore DGB allineate alla Relazione di Servizio IT

Data: 2026-10-05 · Software 1.10.04 · Schema 1.10.04 · Upgrade `sql/migration_v1_10_04.sql`

## Verifica eseguita
Prima della release la pagina «Attività & Rendicontazione DGB» usava **tre logiche diverse** per le stesse ore:

| Sezione | Regola v1.10.03 | Ordinarie | Fuori orario / straord. | Reperibilità |
|---|---|---:|---:|---:|
| Riepilogo orario (card) | ore − extra dichiarate | 343.533,0 | 5.665,5 (extra) | — |
| Dettaglio aggregato (v1.9.98) | ore − extra, esclusa rep. | 343.378,5 | 5.329,0 (extra) | 491,0 |
| Quadro del periodo | PmOrario + eccezione turni, rep. sovrapposta | 333.638,5 | 15.560,0 | 491,0 |
| Distribuzione temporale | PmOrario + eccezione turni | 333.529,0 | 15.178,5 | 491,0 |
| **Relazione IT (riferimento)** | **PmOrario, rep. = on_call/modalità** | | | |

## Modifiche
- `DgbModel::classi()`: unica classificazione, identica a `ItServiceModel::oreClassi()` — reperibilità (`during_availability`, = `on_call` del rapportino) per intero; ordinarie = sovrapposizione con le fasce `pm_orario_fasce` (`PmOrario::ordinarieSql`) esclusa la reperibilità; fuori orario = ore − ordinarie esclusa la reperibilità. Ordinarie + fuori orario + reperibilità = ore consuntivate.
- Rimossa l'eccezione «turni» (chi lavora a turni = tutto ordinario), assente nella Relazione IT.
- Applicata a: riepilogo orario (card), Quadro del periodo, distribuzione temporale (mesi/giorni, CSV/XLSX/SVG), Dettaglio aggregato (pagina + export XLSX), matrice oraria (fasce configurate, reperibilità mai ordinaria), API `dgb_api.php` (`hours_breakdown` con `oncall`, `extra_declared`).
- Dettaglio aggregato: colonna «Straordinario» sostituita da «Fuori orario»; nuova colonna informativa «Extra dichiarate».
- `DgbSync`: scrive `end_at` (= fine attività DGB) sui moduli di intervento sincronizzati, senza sovrascrivere un valore presente.
- Migration: backfill `end_at` dei moduli DGB mancanti, versione 1.10.04.

## Esito (dataset di collaudo, 72.720 allocazioni, 349.198,5 h)
Ordinarie 330.615,5 h · Fuori orario 18.092,0 h · Reperibilità 491,0 h · Extra dichiarate 5.665,5 h (informativa).
Confronto riga per riga col codice della Relazione IT su 64.355 moduli: 0 differenze.
