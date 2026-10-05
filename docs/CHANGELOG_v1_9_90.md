# CHANGELOG — v1.9.90

Relazione di Servizio IT (`it_service.php`) — sezioni «Riepilogo per Codice Contratto» (v1.9.35) e «Dettaglio per commessa» (v1.9.34).

## Isolamento del problema
| Elemento | Esito |
|---|---|
| Pagina | `it_service.php` (+ `app/ItServiceModel.php`, `app/it_service_print.php`) |
| Sezioni non allineate | Riepilogo per Codice Contratto, Dettaglio per commessa (anche righe on-demand, stampa, Word) |
| Blocco filtri secondario | barra di **ListFilter auto** (footer.php → `ListFilter::renderAuto`): ricerca, filtri per colonna, viste salvate ed export **client-side**, agganciata alla tabella con più righe della pagina; filtrava solo le righe a video, non i totali, non le altre sezioni, non stampa/export, e non era sincronizzata col pannello |
| Componente duplicato | `pm-ui-boost` (patch v1.9.34): secondo motore multi-select sugli stessi `select.pm-ms` già gestiti da `pm-multiselect` (header) |
| Selezione dati propria | le due sezioni (rsiWhere) leggevano le attività DGB con regole proprie: data attività, incaricato per nome operatore DGB, cliente dall'anagrafica DGB → settembre 2026: 9.505 h / 197 contratti contro 7.891,5 h / 189 commesse dei KPI; filtro «Cliente» = 0 righe |
| Bug latente | `header.php` riusa `$it` nel ciclo del menu: ogni `$it->…` dopo l'header falliva |

## Correzione
- `$GLOBALS['PM_NO_AUTOFILTER'] = true` sulla pagina: nessuna barra secondaria; il pannello principale è l'unico controller dei filtri.
- Rimosso l'include di `pm-ui-boost` dalla pagina: un solo componente multi-select (`pm-multiselect`).
- `ItServiceModel::rsiFrom()`: le due sezioni partono dai **moduli di intervento del perimetro unico** (`perimetro()`, stessi
  filtri di KPI, grafici, tabelle, costi, giorni), collegati all'allocazione DGB (`dgb_source_id`) e al contratto. Ore e data del modulo;
  reperibilità = allocazione in reperibilità o modulo `on_call`. Rimosso `rsiWhere`.
- Le sezioni mostrano i filtri applicati, la colonna «Ore totali» e la quadratura con i KPI; le attività DGB senza modulo di
  intervento sono dichiarate a parte (`attivitaSenzaModulo()`).
- Calcoli che usano il modello spostati prima di `header.php`.

## Verifica (settembre 2026) — Riepilogo per contratto vs KPI
| Filtro | KPI ore | Contratti/commesse KPI | Riepilogo ore | Contratti |
|---|---|---|---|---|
| nessuno | 7.891,5 | 189 | 7.870,0 | 189 |
| incaricato | 191,5 | 14 | 191,5 | 14 |
| cliente «Regione» | 186,5 | 3 | 186,5 | 3 (prima 0) |
| reperibilità | 58,5 | 8 | 58,5 | 8 |
| linea / codice linea | 2.961,5 | 24 | 2.953,5 | 24 |
Scarti residui (≤ 0,3%): moduli la cui attività DGB è annullata o assente.
