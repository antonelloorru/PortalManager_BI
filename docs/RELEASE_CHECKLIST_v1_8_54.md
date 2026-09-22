# Release Checklist — PortalManager v1.8.54

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.53.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.54` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| altri 5 ROOT + 7 in `app/` | invariati da v1.8.53 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.54**
- [x] Release **solo additiva**: nessun dato modificato o eliminato

## 2. Anomalie implementate e misurate

| Controllo | Severità | Segnalazioni | Tecnici | Ore |
|---|---|---|---|---|
| Ore identiche su più commesse | alta | **1.459** | 51 | 7.268,50 |
| Ore giornaliere oltre 24 h | alta | **14** | 2 | 501,00 |
| Ore giornaliere fra 12 e 24 h | media | **767** | 59 | 11.675,00 |

- [x] Chiave del primo controllo: `(operatore, giorno, ore, orario di inizio)`
- [x] L'orario di inizio è nella chiave: senza, due interventi da due ore mattina
      e pomeriggio verrebbero segnalati come anomalia
- [x] Incidenza 2,2% su 67.786 allocazioni: nell'ordine di grandezza atteso per
      un errore di compilazione
- [x] Casi verificati singolarmente: il primo per gravità è 40 ore in una giornata,
      cinque righe da 8 ore su due commesse tutte con inizio alle 09:00

## 3. Controllo valutato e scartato

- [x] Sovrapposizione temporale: **14.058** coppie, escluso
- [x] Motivo misurato: **46%** delle attività (32.550 su 69.832) dichiara durate
      oltre le 8 ore, perché `date_dead_line` è spesso la finestra di
      disponibilità e non l'orario di esecuzione
- [x] Segnalarlo ad alta severità avrebbe prodotto migliaia di falsi positivi e
      fatto ignorare anche le 1.459 anomalie vere
- [x] Decisione **documentata con la misura** nel Technical Design, così è
      rivedibile se la qualità del dato migliorerà

## 4. QA — correzioni in corso di collaudo

- [x] Primo tentativo su `forms_contract`: tabella del gestionale, **non
      esistente** nel portale. Corretto
- [x] Secondo tentativo su `dgb_forms_contract`: tabella **vuota** (0 righe), il
      dettaglio commesse risultava sempre NULL. Corretto usando `cm_projects`,
      riconciliata su `dgb_contract_id`
- [x] Verificato dopo la correzione: il dettaglio riporta i codici reali
      (`WTS_3351, WTS_3910`)

## 5. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN4 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c54` fresco (132 tabelle) | 393 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c54` | 393 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c54` | 393 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **386 → 393**

## 6. Scelte di progetto

- [x] **Viste e non tabella**: una tabella materializzata resterebbe disallineata
      fra un import e la ricostruzione, mostrando anomalie già corrette o
      nascondendo quelle nuove
- [x] **Contatore sulla linguetta**: un controllo che va aperto per sapere se ha
      qualcosa da dire viene dimenticato; compare solo se maggiore di zero, per
      non diventare rumore visivo
- [x] **Segnalazioni, non verdetti**: dichiarato in pagina e nei manuali, con
      l'avvertenza che alcune ore identiche sono legittime
- [x] Elenco limitato a 500 righe, ordinato per severità e data
- [x] Soglie in `app_settings`, con l'avvertenza che sono presenti anche nelle
      viste e un cambio richiede una release

## 7. Documentazione

- [x] `CHANGELOG.md` — anomalia richiesta, seconda aggiunta, terza scartata
- [x] `TECHNICAL_DESIGN_v1_8_54.md` — criterio di utilità di un controllo,
      selettività della chiave, misura che ha motivato l'esclusione
- [x] `DEPLOYMENT_v1_8_54.md` — verifica, ordine di lavorazione suggerito
- [x] `MANUALE_ADMIN_v1_8_54.md`, `MANUALE_UTENTE_v1_8_54.md`
- [x] `RELEASE_CHECKLIST_v1_8_54.md` — questo documento

## 8. Aperto

- Le 1.459 segnalazioni di ore duplicate riguardano dati **storici**: correggerle
  richiede di intervenire sul gestionale, che è la fonte. Il portale le segnala e
  le fa sparire alla risincronizzazione, ma non può correggerle da sé.
- Il controllo sulla sovrapposizione resta scartato finché `date_dead_line` non
  distinguerà finestra di pianificazione ed esecuzione effettiva. Se quel dato
  arrivasse, il controllo andrebbe reintrodotto: è il più sensibile dei tre.
