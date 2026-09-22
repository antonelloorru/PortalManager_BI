# Technical Design — PortalManager v1.8.59

## 1. Un'assenza che era corretta

La v1.8.58 aveva letto l'assenza di ore su WTS-REP come un sintomo: 2,17 milioni
di valore e 2 ore consuntivate sembravano indicare ore imputate altrove.

Era una deduzione plausibile e sbagliata, e vale la pena capire perché. Il
ragionamento assumeva che ogni contratto con un valore debba generare consuntivo
— assunzione ragionevole per sei modelli su sette, falsa per un canone di
disponibilità.

Il dato non conteneva l'informazione necessaria a distinguere i due casi: solo
la conoscenza del funzionamento contrattuale poteva farlo. È il limite
strutturale di un'analisi fatta sui soli dati, e la ragione per cui una regola
di business fornita dall'azienda vale più di qualunque inferenza statistica.

L'inversione è netta: **l'assenza di ore era la norma, le due ore presenti erano
l'anomalia**.

## 2. L'attributo sta sul modello, non sulla linea

```sql
ALTER TABLE cm_contract_models
    ADD COLUMN allows_reports  tinyint(1) NOT NULL DEFAULT 1,
    ADD COLUMN operative_lines varchar(200) DEFAULT NULL;
```

Si sarebbe potuto scrivere `WHERE service_line = 'WTS-REP'` nella vista. Sarebbe
stato più breve e avrebbe funzionato oggi.

Mettere l'attributo sul modello costa due colonne e rende la regola dichiarativa:
se domani un altro canone di disponibilità venisse introdotto, si imposta il flag
senza toccare il codice. `operative_lines` porta con sé anche l'informazione su
*dove* gli interventi vanno spostati, che serve al suggerimento.

## 3. Il suggerimento, e i suoi limiti

```sql
SELECT q.project_code FROM cm_projects q
 WHERE q.client_id = p.client_id
   AND FIND_IN_SET(q.service_line, m.operative_lines)
   AND (q.start_date IS NULL OR q.start_date <= r.report_date)
   AND (q.end_date   IS NULL OR q.end_date   >= r.report_date)
 ORDER BY CASE WHEN q.operational_status = 'Aperta' THEN 0 ELSE 1 END,
          q.start_date DESC
 LIMIT 1
```

Tre criteri: stesso cliente, linea ammessa, commessa attiva alla data
dell'intervento. L'ordinamento preferisce le commesse aperte.

**Il numero di alternative è esposto** accanto al suggerimento. Con una sola
candidata il suggerimento è quasi certo; con tre — il caso reale — è indicativo, e
la scelta spetta a chi conosce l'intervento. Un suggerimento presentato come
certezza quando non lo è produce correzioni sbagliate, che sono peggio
dell'errore originale perché sembrano risolte.

### Un criterio scartato

Sui dati, WTS_3092 (REP) e WTS_3093 (CC) hanno codici consecutivi e appartengono
allo stesso cliente. La tentazione di usare la contiguità dei codici come
criterio di abbinamento era forte, e avrebbe dato il risultato "giusto" su questo
caso.

È stata scartata: la numerazione progressiva non è una relazione dichiarata. Un
criterio che funziona per coincidenza fallisce senza preavviso, e fallisce in
silenzio — suggerendo una commessa plausibile ma arbitraria.

## 4. Escludere dall'effort, non dal ricavo

```sql
CREATE VIEW v_cm_redditivita_operativa AS
SELECT c.* FROM v_cm_redditivita_commessa c
LEFT JOIN cm_contract_models m ON m.service_line = c.linea_servizio
WHERE COALESCE(m.allows_reports, 1) = 1;
```

Le 54 commesse WTS-REP comparivano nelle medie di effort come commesse a zero
ore, abbassando la media da 50,9 a 35,7 — un errore del 43% su un indicatore che
qualcuno userebbe per dimensionare un team.

Ma il canone **è incassato**: escluderle anche dai totali di ricavo
sottostimerebbe il fatturato di 2,17 milioni. Da qui due viste, non un filtro
globale: `v_cm_redditivita_commessa` per il denaro, `v_cm_redditivita_operativa`
per l'effort.

È lo stesso principio della v1.8.58: la vista giusta dipende dalla domanda, e
imporne una sola costringe a risposte sbagliate a metà delle domande.

## 5. Dove compare la segnalazione

In testa alla scheda **Anomalie orarie**, sopra i filtri.

Non è un'anomalia oraria — le ore sono corrette, è la commessa a essere
sbagliata — ma la conseguenza è economica: il costo si accumula sulla commessa
sbagliata, e le due commesse hanno modelli contrattuali diversi con logiche di
margine diverse.

Metterla in una scheda separata l'avrebbe resa invisibile: chi controlla le
anomalie apre una pagina, non tre.
