# Technical Design — PortalManager v1.8.78

## 1. Una tabella senza chiave naturale

`dgb_forms_activity_operator` ha una chiave primaria tecnica — `id` — e nessun
vincolo sulla combinazione che identifica il fatto: **un'attività, un operatore**.

Con `id` come unica chiave, una seconda scrittura della stessa allocazione è
un `INSERT` legittimo. Il database non ha modo di sapere che i due record
descrivono la stessa cosa.

È lo stesso vuoto strutturale della grana dei rapporti (v1.8.50–51), risolto
allora con `source_uid`. Qui la chiave naturale è più semplice — non serve
costruirla, esiste già come coppia di colonne — ma non era stata dichiarata.

## 2. Conservare l'id più alto

```sql
CREATE TABLE tmp_alloc_keep78 AS
SELECT MAX(id) AS keep_id FROM dgb_forms_activity_operator
 GROUP BY id_activity, id_operator;
```

Sui dati la scelta è indifferente: tutti e 77 i gruppi hanno valori identici,
quindi qualunque riga si tenga il risultato è lo stesso.

Ma la regola deve reggere il caso che oggi non si presenta. Se un giorno i valori
differissero — revisione anziché copia — l'ultima riga scritta è quella che
riflette lo stato corrente della sorgente.

`CREATE ... AS SELECT` per la tabella d'appoggio eredita i tipi dalla sorgente:
è la lezione della v1.8.66, dove una temporanea dichiarata a mano aveva ereditato
la collation sbagliata.

## 3. Il vincolo dopo la pulizia

```sql
ALTER TABLE dgb_forms_activity_operator
  ADD UNIQUE KEY IF NOT EXISTS uq_dfao_activity_operator (id_activity, id_operator);
```

L'ordine è obbligato: applicato prima, fallirebbe sulle 77 righe esistenti e
interromperebbe la migration.

È anche il motivo per cui la deduplica non poteva essere lasciata alla sola
riconciliazione (v1.8.71): quella confronta con la sorgente e qui la sorgente
**ha** entrambe le righe finché la sincronizzazione non le deduplica a monte. Il
vincolo agisce sul database di destinazione, che è dove il duplicato fa danno.

## 4. `extra_hours` compreso in `hours`

L'indicazione dell'azienda ribalta il trattamento: nelle 9 ore, 4 sono extra e 5
ordinarie.

```php
ROUND(SUM(ao.hours - COALESCE(ao.extra_hours,0)),2) AS ordinary,
ROUND(SUM(ao.hours),2)                              AS total_hours,
ROUND(SUM(ao.extra_hours),2)                        AS overtime
```

Prima `ordinary` era `SUM(hours)` e `overtime` `SUM(extra_hours)`: chiunque
sommasse i due campi otteneva le extra contate due volte.

Ora i tre valori sono coerenti fra loro: `ordinary + overtime = total_hours`.

## 5. Due assi indipendenti

La distinzione **ordinarie/extra** è contrattuale e viene dichiarata sul modulo.
La distinzione **in orario / fuori orario** è temporale e si calcola dagli orari.

Non sono la stessa cosa e non sono nemmeno correlate: un intervento tutto dentro
la fascia 09–13 può essere interamente straordinario se il tecnico ha già
completato il proprio monte ore, e un intervento notturno può essere ordinario
per un turnista.

Esporre entrambe le coppie è l'unico modo di non far scegliere all'utente quale
delle due sta guardando.

## 6. Il fuori orario per differenza

```sql
ROUND(hours, 2) - ROUND(hours * frazione_in_orario, 2) AS ore_fuori_orario
```

Non una seconda formula simmetrica. Calcolarlo indipendentemente lascerebbe
scarti di arrotondamento: due `ROUND` su due espressioni diverse non sommano
esattamente al totale.

Per differenza, `in_orario + fuori_orario = consuntivate` è vero per
costruzione. Verificato su 69.106 allocazioni: **scarto 0,00**.

È lo stesso principio della v1.8.53, dove la reperibilità era calcolata per
differenza dall'ordinario.

## 7. Il riscontro indipendente

La quota fuori orario risulta **13,5%**. La classificazione della v1.8.53,
costruita su una vista diversa e con un percorso diverso, ottiene **13,48%**.

Due misure che convergono senza essere state derivate l'una dall'altra: è il
riscontro che rende utilizzabile un indicatore nuovo.
