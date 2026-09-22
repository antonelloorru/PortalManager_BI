# Technical Design — PortalManager v1.9.10

## 1. Il perimetro come parametro

`sd_linee_perimetro` contiene cinque codici. Nel codice sarebbe stato un `IN (…)`
in cinque viste.

La domanda «quali contratti sono Service Desk» ha una risposta aziendale che può
cambiare: se un domani WTS-MON entrasse nel perimetro, con il parametro è un
`UPDATE`, nel codice è una release.

`FIND_IN_SET` invece di `IN` perché il valore è una stringa unica: `IN` avrebbe
richiesto SQL dinamico, che in una vista non è possibile.

## 2. Tre misure di «addetti», nessuna scelta implicita

Il KPI chiedeva «n° medio addetti». Le misure possibili danno numeri molto
diversi: chi ha fatto un intervento in un anno conta come un addetto per la prima
misura e come un ventesimo per la terza.

Sceglierne una e chiamarla «media» avrebbe nascosto la scelta. Esporle tutte e tre
costa tre colonne e rende la domanda esplicita — a cui il responsabile può
rispondere sapendo cosa sta scegliendo.

Il denominatore dei mesi viene dai dati (`COUNT(*) FROM v_cm_sd_addetti_mese`), non
dal calendario: un mese senza interventi non deve abbassare la media, perché non è
un mese di attività ridotta ma un mese senza attività registrata.

## 3. Il tasso di escalation sui presi in carico

```sql
SUM(gestione = 'escalation…') / COUNT(*) WHERE gestione <> 'mai preso in carico'
```

Terza volta che questa regola si ripresenta (v1.8.84, v1.8.86, ora). Vale la pena
fissarla: un ticket mai preso in carico non è un ticket che il primo livello ha
scelto di non scalare — è un ticket che nessuno ha visto.

Al denominatore abbasserebbe il tasso per una ragione che non riguarda la capacità
del primo livello, e un peggioramento del presidio si presenterebbe come un
miglioramento dell'escalation.

## 4. `durata_ore` non è il tempo di prima risposta

Il collaudo ha rilevato che `v_cm_sd_ticket` non ha `ore_prima_risposta`: espone
`durata_ore`, che è apertura fino all'ultimo messaggio.

Sono due grandezze diverse. Chiamare la seconda col nome della prima avrebbe
prodotto una colonna che un responsabile legge come SLA e che non lo è.

La media è sui soli presi in carico: la durata di un ticket mai preso misura
l'attesa fino alla chiusura automatica, non la lavorazione.

## 5. `margin_total` e non la ricostruzione

La v1.9.2 ha accertato che `value_total - actual_cost` diverge dal margine del
gestionale su 194 commesse su 1.092, per 1,3 milioni.

Qui si legge `margin_total`. Le viste dei pannelli continuano invece a
ricostruirlo: è un'incoerenza nota e dichiarata, che va sanata quando il cliente
avrà verificato lo scostamento.

## 6. Perché OBJ_2.1 e 2.2 restano fuori

`cm_sd_messages` ha `ticket_code`, `msg_type`, `queue_id`, `queue_name`,
`author_name`, `received_at`. Nessun riferimento alla commessa, al cliente, al
contratto.

Le strade possibili erano tre, tutte fondate su un'assunzione non verificabile:
dedurre il contratto dalla coda, dal cliente, o dal periodo di apertura incrociato
con le commesse attive.

Ciascuna avrebbe prodotto numeri che si sommano, si ripartiscono in percentuali e
sembrano un'analisi. La v1.9.1 ha già mostrato cosa succede quando si costruisce
un'analisi su una regola dedotta: cinque milioni di scostamento annunciati con
sicurezza e sbagliati.

La differenza fra qui e là è che là l'assunzione sembrava fondata su un documento.
Qui sarebbe dichiaratamente inventata.
