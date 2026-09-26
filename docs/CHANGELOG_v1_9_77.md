# PortalManager v1.9.77 — Relazione di Servizio IT: filtro globale Codice Contratto / PM Project

## UI
- Nuovo gruppo **Contratto** in testa al pannello filtri: select multipla con ricerca
  (`pm-ms`) «Codice Contratto / PM Project».
- Opzioni: `project_code · <codice contratto DGB | Contratto #id> · <cliente>` per ogni
  commessa presente nella Relazione IT; in coda i contratti DGB **senza PM Project** collegato
  (`<codice | Contratto #id> · DGB senza PM Project · <cliente>`, valore `dgb:<id>`).
- Il filtro conta nel badge dei filtri attivi, si propaga ai link di stampa/export (`$qs`)
  e alla richiesta AJAX del dettaglio per contratto (eredita l'URL corrente).

## Logica — un filtro, due chiavi fisiche
| Dataset | Colonna | Condizione |
|---|---|---|
| `v_cm_it_servizio` (KPI, aggregazione, pivot, barre per dimensione, andamento mensile, **andamento giornaliero**, stato km) | `s.commessa` | `ItServiceModel::where()` → `ctrCond()` |
| `v_cm_sd_costi_valorizzati` (riepilogo/quadro costi) | `commessa` | `costiQuery()` → `ctrCond()` |
| `v_cm_it_giorni_base` (giorni per operatore/area/quadro, riconciliazione) | `commessa` | `giorniQuery()`, `giorniRiconcilia()` → `ctrCond()` |
| `dgb_forms_activity` (Riepilogo per contratto, Dettaglio per commessa: sintesi, AJAX, stampa, DOCX) | `a.id_contract` | `rsiWhere()` → `ctrCondDgb()` |

Ponte bidirezionale `cm_projects.dgb_contract_id`:
- PM Project scelto → rapportini con quel `project_code` **e** attività DGB del contratto collegato;
- contratto DGB scelto (`dgb:<id>`) → attività DGB del contratto **e** rapportini delle commesse
  collegate a quel contratto.

Più valori = OR; combinazione con gli altri filtri = AND. Tutti i valori passano come
parametri preparati; `normContratti()` accetta solo `dgb:<intero>` o codici ≤ 64 caratteri
senza caratteri di controllo.

## Stampa / export
- `ItServiceModel::descrizioneFiltri()`: descrizione unica dei filtri per stampa PDF, DOCX e
  XLSX (prima duplicata in due punti e priva di codici linea, aziende, ricerca e cliente).
- XLSX: nuovo foglio **Filtri** (periodo, filtri applicati, data di generazione).

## Non filtrato (per scelta)
- «Coppie sede-cliente senza distanza» (`v_cm_it_distanze_mancanti`): elenco di qualità dati
  globale, indipendente da periodo e filtri.

## QA
- Test su dump BI (70.549 righe `v_cm_it_servizio`, 83.723 attività DGB), periodo 01/01–31/08/2026:
  - WTS_3016 (contratto 77): 19.398 → 1.751 interventi; riepilogo contratto 409 → 1 (id 77);
    stesso risultato selezionando `WTS_3016`, `dgb:77` o entrambi;
  - WTS_3053 (contratto 114): costi 567 = conteggio diretto sulla vista; dettaglio DGB 578
    righe, solo contratto 114; AJAX dettaglio con filtro su altra commessa → 0 righe;
  - valore malevolo `x' OR 1=1 --` → 0 righe su tutti i dataset;
  - stampa, DOCX e XLSX riportano il filtro; opzioni 798 in 0,6 s.
- `php -l` su tutti i file; migration RUN1/RUN2 err=0.
