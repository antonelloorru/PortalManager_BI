# Technical Design — PortalManager v1.8.79

## 1. Chiave tecnica contro chiave naturale

Il dataset dichiara `'key' => 'id'`: è l'identificativo con cui `DatasetSync`
decide se una riga esiste già.

Ma `id` è una chiave **tecnica** della sorgente. Il fatto è identificato dalla
coppia (attività, operatore), che è la chiave **naturale**.

Finché le due coincidono — un id per ogni coppia — il meccanismo funziona.
Quando la sorgente contiene due id per la stessa coppia, `DatasetSync` li vede
come due fatti distinti e li inserisce entrambi.

Il vincolo della v1.8.78 ha reso l'incoerenza un errore invece di un dato
sbagliato. È il comportamento giusto, ma va accompagnato dalla correzione a
monte: un errore che si ripete a ogni sincronizzazione smette di essere letto.

## 2. Deduplica nella query, non nella scrittura

```sql
JOIN (
    SELECT MAX(id) AS keep_id FROM forms_activity_has_dgb_operator
     GROUP BY id_activity, id_operator
) k ON k.keep_id = ao.id
```

L'alternativa era gestire la collisione in `DatasetSync` — intercettare l'errore
1062 e passare a un `UPDATE`. Scartata: renderebbe il codice più complesso per
compensare un dato che si può filtrare a monte, e nasconderebbe la differenza fra
"duplicato atteso" e "collisione imprevista".

Filtrando nella query, quello che arriva a `DatasetSync` è già coerente, e un
eventuale 1062 residuo torna a essere un segnale genuino.

`MAX(id)` è la stessa regola della deduplica sul portale (v1.8.78): due criteri
diversi per lo stesso problema divergerebbero al primo caso in cui i valori non
sono identici.

## 3. Due dataset sulla stessa tabella

`allocazioni_dgb` e `rapporti` leggono entrambi
`forms_activity_has_dgb_operator`.

Correggere solo il primo avrebbe risolto l'errore visibile — quello che l'utente
segnalava — lasciando il secondo a generare due rapporti per lo stesso
intervento. Le ore sarebbero tornate a raddoppiare, e il difetto sarebbe
riemerso dopo la prima sincronizzazione, apparentemente senza causa.

È la regola già formulata nella v1.8.63: **quando due dataset leggono la stessa
tabella, un difetto della sorgente li riguarda entrambi.**

## 4. `COUNT(*)` contro `COUNT(DISTINCT id_operator)`

Il dataset `rapporti` conta le allocazioni per attività, e usa quel numero per
ripartire i valori economici dell'attività fra gli operatori.

Con un duplicato, `COUNT(*)` restituisce 3 dove gli operatori sono 2: ogni quota
viene divisa per 3, e ciascun operatore riceve meno del dovuto.

È un difetto che il vincolo non avrebbe mai segnalato — non produce righe in più,
solo valori più bassi. È emerso leggendo la query per correggere altro.

## 5. Il vincolo rimosso e riapplicato

```sql
ALTER TABLE dgb_forms_activity_operator DROP INDEX IF EXISTS uq_dfao_activity_operator;
… pulizia …
ALTER TABLE dgb_forms_activity_operator ADD UNIQUE KEY IF NOT EXISTS …;
```

Una sincronizzazione fallita a metà può aver lasciato la tabella in uno stato che
la `DELETE` deve poter correggere senza interferenze.

Rimuovere e riapplicare costa una riscrittura dell'indice su 71.000 righe —
pochi secondi — e rende la migration eseguibile su qualunque stato di partenza,
che è la proprietà che serve a una correzione d'emergenza.

## 6. Il difetto propagato ai rapporti

`v_cm_rapporti_doppi_attivita` elenca gli interventi che hanno generato più di un
rapporto per lo stesso tecnico.

La grana `source_uid` (v1.8.50–51) non li intercettava: due allocazioni sorgente
producono due `report_code` diversi, quindi due grane diverse. Il vincolo
funziona, ma protegge da una collisione che qui non si verifica.

È lo stesso limite emerso nella v1.8.70 con i codici `DGB-<id>`: una chiave
sintattica non riconosce che due codici diversi denotano lo stesso fatto. Serve
un controllo sulla semantica, ed è questa vista.
