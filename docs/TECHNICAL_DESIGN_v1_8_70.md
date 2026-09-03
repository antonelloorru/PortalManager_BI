# Technical Design — PortalManager v1.8.70

## 1. Un vincolo che funziona e non basta

`uq_ir_source_uid` è UNIQUE su `source_uid`, e la grana è
`<codice rapporto>#<identificativo tecnico>`.

Il vincolo fa esattamente ciò per cui è stato costruito: impedisce che lo stesso
codice rapporto venga importato due volte. Le v1.8.50 e v1.8.51 hanno risolto
quel problema, e la v1.8.66 ha fatto in modo che il vincolo venisse davvero
applicato.

Ciò che nessun vincolo può fare è accorgersi che **due codici diversi denotano lo
stesso fatto**. `DGB-14470` e il codice reale del gestionale sono stringhe
diverse: per il database sono due rapporti distinti.

È il limite strutturale di una chiave sintattica. L'unica difesa sarebbe stata
non generare mai codici inventati — che è ciò che la v1.8.51 ha corretto, senza
però ripulire il pregresso.

## 2. Riconoscere il vecchio import

Tre indizi convergenti, di cui il terzo decisivo:

| Indizio | Evidenza |
|---|---|
| prefisso `DGB-` nel codice | 67.786 righe |
| `project_code` fittizio `DGB-<id>` | sulle righe isolate |
| **`dgb_activity_id` NULL** | **0 su 67.786**, contro 68.079 su 69.042 |

Il terzo è decisivo perché `dgb_activity_id` è stato **introdotto** dalla
v1.8.51: la sua assenza data la riga con precisione, indipendentemente da come
appare il codice.

## 3. Due condizioni nel criterio, non una

```sql
DELETE FROM cm_intervention_reports
 WHERE report_code LIKE 'DGB-%' AND dgb_activity_id IS NULL;
```

Il solo prefisso sarebbe bastato sui dati attuali. Non è stato usato da solo
perché un import futuro potrebbe legittimamente produrre un codice con quel
prefisso — e avrebbe comunque `dgb_activity_id` valorizzato.

La seconda condizione rende il criterio **descrittivo del difetto** anziché dei
suoi sintomi: non "le righe che sembrano vecchie", ma "le righe prodotte da un
import che non popolava l'identificativo dell'attività".

## 4. Prova prima di cancellare

Prima di scrivere la `DELETE` ho verificato che le righe fossero davvero
ridondanti, non semplicemente diverse:

```sql
SELECT COUNT(*) FROM cm_intervention_reports a
 WHERE a.report_code LIKE 'DGB-%' AND EXISTS (
   SELECT 1 FROM cm_intervention_reports b
    WHERE b.report_code NOT LIKE 'DGB-%'
      AND b.technician_raw = a.technician_raw
      AND b.report_date = a.report_date
      AND b.quantity_hours = a.quantity_hours);
```

63.202 su 67.786 — il 93,2% — hanno un gemello esatto. Altre 4.575 hanno un
rapporto reale dello stesso tecnico nello stesso giorno, con ore che differiscono
solo per arrotondamento. Le 9 restanti hanno commesse fittizie.

Senza questa verifica la `DELETE` sarebbe stata un atto di fede su 67.786 righe.

## 5. Il log della pulizia

`cm_cleanup_log` registra righe e ore prima e dopo.

Dopo una `DELETE` non resta modo di sapere che cosa è stato rimosso: il dato non
c'è più. Un log scritto **nella stessa transazione** conserva la misura, e
permette mesi dopo di rispondere alla domanda «perché le ore sono calate».

L'`INSERT` è condizionato a `WHERE (…) > 0`: alla seconda esecuzione non ci sono
righe da rimuovere e il log non viene duplicato con una riga di zeri.

## 6. Le commesse fittizie

```sql
DELETE FROM cm_projects
 WHERE project_code LIKE 'DGB-%'
   AND NOT EXISTS (SELECT 1 FROM cm_intervention_reports r WHERE r.project_id = cm_projects.id);
```

`DatasetSync` già rimuove le commesse `DGB-` quando trova la corrispondenza
reale. Quelle rimaste orfane vengono tolte qui, ma **solo se non hanno più
rapporti collegati**: cancellare una commessa ancora referenziata lascerebbe
rapporti senza progetto, sostituendo un problema con un altro.

## 7. Il controllo permanente

`v_cm_residui_import` usa `HAVING COUNT(*) > 0`, quindi restituisce **zero
righe** quando tutto è a posto — la forma giusta per un controllo che si guarda
di sfuggita.

Vale più della pulizia stessa: la pulizia risolve oggi, il controllo segnala se
un server con una versione vecchia di `DgbSync` ricomincia a produrre codici
inventati.
