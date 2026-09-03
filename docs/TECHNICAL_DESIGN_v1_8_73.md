# Technical Design — PortalManager v1.8.73

## 1. Due basi dati per lo stesso fatto

Il portale rappresenta l'intervento due volte:

- `dgb_forms_activity` + `dgb_forms_activity_operator` — la struttura del
  gestionale, con orari, stato e ripartizione per operatore;
- `cm_intervention_reports` — il fatto normalizzato, con la grana `source_uid`.

Non è una duplicazione accidentale: la prima serve alla pagina DGB, che analizza
orari e carico; la seconda all'analisi economica per commessa. Hanno grane
diverse e rispondono a domande diverse.

Il difetto era che **solo la seconda era alimentata dalla sincronizzazione**. La
prima dipendeva da `DgbSync`, invocato da un'altra pagina, e nessuno lo lanciava
più.

## 2. Perché la divergenza non si era notata

Le due basi divergono **lentamente e in un solo verso**: quella non aggiornata
resta indietro. Finché lo scarto è di giorni non si vede; a settimane produce un
grafico che finisce prima del previsto.

E il confronto che l'utente ha fatto — controllare i moduli sulla singola
commessa — usava la base **aggiornata**, quindi confermava che i dati c'erano.
Erano due letture di tabelle diverse, entrambe corrette dal proprio punto di
vista.

È il genere di difetto che non produce errori: produce due verità, ciascuna
coerente con sé stessa.

## 3. La correzione strutturale, non il rilancio dell'import

Si poteva dire all'utente di lanciare l'import DGB. Avrebbe risolto oggi e si
sarebbe ripresentato al prossimo mese di distrazione.

Portare le tabelle dentro i dataset elimina la possibilità: non esistono più due
procedure con due tempi di esecuzione, ma una sola che aggiorna tutto.

È lo stesso ragionamento della v1.8.63, quando è stato rimosso l'import da
tabella singola: due meccanismi paralleli che fanno cose sovrapposte divergono,
ed è una questione di quando, non di se.

## 4. Ordine nella sequenza

```
… → commesse → … → attivita_dgb → allocazioni_dgb → rapporti
```

Le attività dopo le commesse, perché vi si agganciano per `id_contract`. Le
allocazioni dopo le attività, a cui si riferiscono per `id_activity`. I rapporti
per ultimi, come già erano.

L'ordine non è vincolante per la correttezza — gli agganci avvengono comunque —
ma evita che una sincronizzazione appena conclusa mostri righe scollegate.

## 5. `import_batch_id` mancante, di nuovo

Le due tabelle DGB non avevano la colonna, richiesta da `DatasetSync` su ogni
destinazione. Senza, la sincronizzazione sarebbe fallita con *Unknown column* —
esattamente il difetto della v1.8.64, che aveva colpito cinque tabelle.

Il controllo `v_cm_sync_schema_check` introdotto allora elenca le destinazioni
prive della colonna, ma il suo elenco è **cablato**: non conosceva le due tabelle
DGB perché non erano ancora destinazioni di un dataset.

Un controllo con un elenco fisso protegge da ciò che sa già. È il suo limite, ed
è la ragione per cui la migration aggiunge la colonna esplicitamente invece di
fidarsi del controllo.

## 6. Il controllo di allineamento

`v_cm_allineamento_dgb` confronta mese per mese le due basi.

La `UNION` dei mesi presenti in entrambe è necessaria: un `LEFT JOIN` da una sola
delle due nasconderebbe i mesi presenti solo nell'altra, cioè proprio il caso da
rilevare.

`pct_copertura` è un rapporto, non una differenza: uno scarto di 400 righe
significa cose diverse su un mese da 2.500 e su uno da 700. La percentuale rende
i mesi confrontabili fra loro.

Non ci si aspetta 100%: un'attività può coinvolgere più operatori e le due
tabelle contano entità leggermente diverse. Il segnale è un mese recente con
copertura vicina a zero.
