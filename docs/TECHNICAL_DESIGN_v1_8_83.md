# Technical Design — PortalManager v1.8.83

## 1. Un aggregato che nascondeva il suo contrario

571 ticket «senza risposta» sembravano una classe omogenea. Scomponendoli per
tipo di messaggio presente:

| Composizione | Ticket | Chiusi |
|---|---|---|
| solo note interne | 430 | 426 |
| cliente + note interne | 129 | 127 |
| solo messaggi del cliente | 12 | **0** |

I primi due gruppi sono **lavoro svolto**: qualcuno ha annotato, e nel 99% dei
casi il ticket è chiuso. Il terzo è **lavoro non svolto**: nessuno ha toccato il
ticket, e nessuno è chiuso.

Tenerli insieme produceva un numero — 571 — che non permetteva nessuna decisione.
Il 2% che conta era invisibile dentro il 98% che non conta.

## 2. La discriminante è il tipo di messaggio, non la sua assenza

```sql
WHEN SUM(msg_type='SUPPORT_MSG') = 0 AND SUM(msg_type='INTERNAL_NOTE') = 0
     THEN 'mai preso in carico'
WHEN SUM(msg_type='SUPPORT_MSG') = 0 AND SUM(msg_type='CUSTOMER_MSG') > 0
     THEN 'cliente senza risposta scritta'
WHEN SUM(msg_type='SUPPORT_MSG') = 0
     THEN 'lavorato senza risposta scritta'
```

L'ordine delle condizioni è vincolante. `mai preso in carico` va per primo perché
è il caso più restrittivo: senza note **e** senza risposte. Invertendo, i dodici
ticket scoperti finirebbero in `cliente senza risposta scritta` e sparirebbero
fra i 129.

La terza condizione cattura ciò che resta — note interne senza messaggi del
cliente — ed è deliberatamente l'ultima: è la più ampia.

## 3. `presidio`: chi ha toccato il ticket

```sql
CASE WHEN SUM(livello='L1')>0 AND SUM(livello='L2')>0 THEN 'L1 e L2'
     WHEN SUM(livello='L1')>0 THEN 'solo L1'
     WHEN SUM(livello='L2')>0 THEN 'solo L2'
     ELSE 'nessuno' END
```

Conta **tutti** i messaggi, note comprese, mentre `gestione` guarda solo quelli di
supporto.

La differenza è il punto: un ticket può risultare `lavorato senza risposta
scritta` con `presidio = 'solo L2'`, e allora si sa che gli specialisti lo hanno
gestito internamente. Con `presidio = 'nessuno'` non lo ha toccato nessuno.

Due campi che rispondono a domande diverse — *come è stato gestito* e *da chi* —
e che insieme dicono da chi non si è avuta risposta.

## 4. La vista dell'azione è selettiva per scelta

```sql
WHERE gestione = 'mai preso in carico'
   OR (gestione = 'cliente senza risposta scritta' AND stato <> 'CLOSED')
```

14 righe su 571. Includere tutti i «senza risposta» avrebbe prodotto un elenco che
nessuno scorre fino in fondo, e i 12 casi urgenti sarebbero rimasti invisibili
come prima.

Il filtro sullo stato sulla seconda classe è necessario: 127 dei 129 sono chiusi,
e un ticket chiuso senza risposta scritta è un rilievo di qualità, non
un'emergenza operativa.

## 5. Gli SLA predisposti e vuoti

`cm_sd_sla` accetta soglie per commessa o per coda, con `project_code` NULL come
default. La struttura c'è, i dati no.

Sarebbe stato facile popolarla con valori plausibili — quattro ore per la presa in
carico, due giorni per la risoluzione — e produrre subito una percentuale di
rispetto SLA.

Sarebbe stato un giudizio travestito da misura. La `durata_ore` che il portale
calcola comprende le attese del cliente, quindi non è nemmeno il tempo giusto da
confrontare: servirebbe il tempo netto, che richiede di sottrarre gli intervalli
in `WAITING_FOR_CUSTOMER_RESPONSE`.

Finché gli SLA non sono definiti, le viste espongono tempi osservati e li
chiamano così.
