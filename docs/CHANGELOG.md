# CHANGELOG — PortalManager

## v1.9.23 — Pagina Ordinativi Pratix

**Gestione Commesse → Ordinativi Pratix.** La v1.9.22 aveva costruito le viste;
questa release aggiunge la pagina.

### Cosa mostra

Un riquadro per ordinativo con l'elenco delle **commesse collegate**: nome
cliente, tipo contratto, descrizione, importo singolo e **link alla commessa**.
In fondo la riga **TOTALE ORDINATIVO**.

Filtri: ricerca ovunque, cliente, e quattro viste — solo anomalie, solo codici
multipli, solo su più commesse, solo con righe senza importo.

Export XLSX con tre fogli.

### Un difetto trovato in collaudo: le chiavi in PHP

Le righe di dettaglio si caricano in **una query sola** e si indicizzano per
codice in un array PHP.

**MariaDB raggruppa con una collation `_ci`**: `a3992` e `A3992` finiscono nello
stesso ordinativo. **Un array PHP invece distingue.**

L'ordinativo sarebbe comparso con il **totale giusto e meno righe di quelle che lo
compongono**: un elenco che non somma al proprio totale, senza alcun errore
visibile.

**Quattro ordinativi su 300** erano in questa condizione. La chiave è ora
normalizzata in maiuscolo, come fa il raggruppamento SQL.

È lo stesso schema della v1.9.12 sugli stati dei moduli: **due sistemi che
confrontano stringhe con regole diverse**.

### Una query sola per le righe

Con 300 ordinativi a video, una query di dettaglio ciascuno sarebbero **300
interrogazioni per una pagina**. Le righe si caricano con un solo `IN` sui codici
mostrati.

### Cosa la pagina dichiara di non poter fare

**La validazione «somma contro totale dichiarato» non è possibile** su 896
ordinativi su 896: `forms_contract_main_order.amount` non è sincronizzato.

La pagina lo dice in un riquadro azzurro in testa, invece di mostrare una colonna
vuota. Un cruscotto che espone una validazione mai eseguita fa credere che sia
passata.

### Le anomalie

**15 celle con più codici** — `C2501 C2500 C2499 C1401`, `A0367 - A0489 - A0452`.
Segnalate e **non divise**: ripartire l'importo richiederebbe una scelta arbitraria
che produrrebbe numeri precisi e inventati.

Gli importi **previsti** sono marcati con una **P** arancione: sommarli agli
consolidati indifferentemente darebbe un totale misto.

### Verifiche — sette scenari

| Scenario | Ordinativi | Righe | Importo |
|---|---|---|---|
| tutti | 300 | 537 | 34.533.530,01 € |
| solo codici multipli | 15 | 34 | 3.579.503,26 € |
| solo anomalie | 51 | 75 | 3.590.062,26 € |
| su più commesse | 135 | 405 | 18.696.205,71 € |
| ricerca «ESTAR» | 23 | 52 | 4.420.860,09 € |
| cliente «ESTAR» | 23 | 52 | 4.420.860,09 € |
| ricerca a vuoto | 0 | 0 | 0,00 € |

In ogni scenario: **la somma delle righe fa il totale dell'ordinativo**, e ogni
ordinativo mostrato ha le sue righe.

| Controllo | Esito |
|---|---|
| Avvisi durante il caricamento | **0** |
| `if`/`endif`, `foreach`, `<div>` | **15/15, 4/4, 24/24** |
| Variabili solo nel catch | **0** |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **767 stmt, err=0** |
