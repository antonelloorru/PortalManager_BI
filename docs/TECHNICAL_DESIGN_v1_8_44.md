# Technical Design — PortalManager v1.8.44

## 1. Due componenti sulla stessa tabella

Diverse pagine sovrappongono due meccanismi indipendenti sullo stesso elemento:

- **DataTables**, che ordina, cerca e **pagina**;
- **ListFilter**, che filtra per colonna ed esporta.

Nessuno dei due sa dell'altro. Finché entrambi si limitano a leggere il DOM la
convivenza regge; smette di reggere quando uno dei due lo modifica in modo che
l'altro non prevede.

## 2. Che cosa fa davvero la paginazione di DataTables

L'assunzione naturale è che le righe fuori pagina siano nascoste via CSS. Non è
così: DataTables **rimuove i nodi dal DOM** e li conserva nella propria struttura
interna, reinserendoli al cambio pagina.

La conseguenza è che, dopo l'inizializzazione:

```js
table.querySelectorAll('tbody tr').length   // 25, non 286
```

Il numero non dipende dai filtri ma dal `pageLength`.

## 3. Perché la v1.8.43 non era sufficiente

La v1.8.43 aveva reso opzionale il predicato sulle righe filtrate:

```js
if (!includeAll && row.classList.contains('lf-hidden')) return;
```

Corretto, ma agiva sul secondo dei due filtri in serie. Il primo — quale insieme
di righe si sta iterando — restava il DOM paginato. Rimuovere il predicato su un
insieme che ne contiene già solo 25 non poteva che dare 25.

È il motivo per cui il test della v1.8.43, pur usando la funzione reale, non
aveva colto il difetto: il DOM simulato conteneva tutte le righe, cioè assumeva
implicitamente l'assenza di paginazione. La correzione era verificata rispetto a
uno scenario che non era quello di produzione.

## 4. La correzione

```js
function getRowNodes() {
    try {
        const jq = window.jQuery || window.$;
        if (jq && jq.fn && jq.fn.dataTable && jq.fn.dataTable.isDataTable(table)) {
            return jq(table).DataTable().rows().nodes().toArray();
        }
    } catch (e) { /* DataTables assente o non inizializzato */ }
    return Array.from(table.querySelectorAll('tbody tr'));
}
```

`rows().nodes()` restituisce i nodi di tutte le righe, anche quelle non
renderizzate. Tre cautele:

**Rilevamento a runtime, non all'inizializzazione.** ListFilter viene inizializzato
prima che lo script di pagina crei la DataTable. Interrogare l'API al momento
dell'uso — click su Esporta, digitazione in un filtro — garantisce che a quel
punto l'istanza esista.

**Ricaduta sul DOM.** Se jQuery manca, se DataTables non è caricato o se la
tabella non è gestita, si legge il DOM come prima. Le pagine senza paginazione
non cambiano comportamento.

**`try/catch` attorno all'intero rilevamento.** Le versioni di DataTables
differiscono nell'esporre `$.fn.dataTable`; un errore lì non deve impedire
l'export, che degrada semplicemente al comportamento precedente.

## 5. Anche i filtri erano troncati

`applyFilters()` iterava sul DOM paginato, quindi marcava `lf-hidden` solo sulle
righe della pagina corrente. Usa ora `getRowNodes()`: il predicato è valutato su
tutte le righe e l'export filtrato è coerente con l'intero insieme, non con la
sola pagina visibile.

Resta una limitazione nota, fuori dal perimetro di questo hotfix: poiché
DataTables ricostruisce il DOM al cambio pagina e calcola il numero di pagine sui
propri dati, la classe `lf-hidden` non influisce sulla sua impaginazione. I
filtri di ListFilter agiscono correttamente su export e conteggio, mentre la
paginazione mostrata resta quella di DataTables.

## 6. Metodo di verifica

Il test riproduce la condizione reale: 286 righe complessive, 25 nel DOM, un'API
DataTables simulata che restituisce l'insieme completo. Le funzioni sono estratte
dal file di release ed eseguite, non riscritte.

| Scenario | Righe esportate |
|---|---|
| Lettura diretta dal DOM (comportamento precedente) | 25 — il difetto |
| `getRowNodes()`, ambito "tutti" | 286 |
| `getRowNodes()`, ambito "filtrate" | 95, pari alle righe non marcate |
| Fallback senza DataTables | 25, cioè quelle nel DOM |

L'ultima riga è il controllo di non regressione: senza DataTables il componente
deve comportarsi esattamente come prima.
