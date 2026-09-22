# Technical Design — PortalManager v1.9.1

## 1. Aggiungere invece di sostituire

`v_cm_margine_formula` è una vista nuova. `v_cm_redditivita_commessa` e le altre
non sono state toccate.

La tentazione era di correggere direttamente le viste esistenti: il calcolo
sbagliato è dimostrato, la correzione è documentata, e il portale avrebbe
cominciato a mostrare i numeri giusti.

Sarebbe stato imprudente per due ragioni. La prima è che uno scostamento del 24%
sul margine complessivo cambia ogni cruscotto contemporaneamente, e chi apre il
portale il giorno dopo non ha modo di sapere perché. La seconda è che un report
stampato la settimana precedente diventerebbe irriconciliabile.

Le due colonne affiancate e `scarto_formula` rendono la differenza ispezionabile
prima di essere adottata.

## 2. Il `CASE` è ripetuto tre volte

La stessa espressione compare in `margine`, in `scarto_formula` e in
`margine_pct`.

Una vista intermedia avrebbe evitato la ripetizione, al costo di un livello di
annidamento in più — e le viste annidate su questo database hanno già dato
problemi con l'ottimizzatore (v1.8.88, dove `IN` ed `EXISTS` restituivano 2 righe
su 520).

La ripetizione è verbosa e verificabile; l'annidamento è compatto e ha già
mostrato di poter mentire.

## 3. Il denominatore della percentuale segue la formula

```sql
WHEN formula = 'D' AND value_todate > 0
     THEN 100 * (value_todate - actual_cost) / value_todate
```

Su un contratto a scalare il margine si realizza sul consumato. Rapportarlo al
plafond darebbe una percentuale che scende man mano che il contratto viene
consumato, anche a redditività costante.

È il genere di errore che produce un numero coerente, monotono e privo di
significato.

## 4. `costi_a_zero` dentro la formula, non prima

```sql
WHEN 'C' THEN value_total + value_todate
            - CASE WHEN cost_basis = 'costi_a_zero' THEN 0 ELSE actual_cost END
```

La base di costo non seleziona una formula diversa: modifica un addendo dentro la
stessa formula.

WTS-SOC ha formula C e base `costi_a_zero`: somma il consuntivato e non sottrae i
costi. Trattare la base come una quinta formula avrebbe moltiplicato i casi
(4 formule × 3 basi = 12) invece di comporli.

## 5. Perché l'errore era invisibile

775 commesse su 1.092 usano la formula B, che coincide con il calcolo che il
portale applicava a tutte.

Un errore che riguarda il 71% dei casi si nota subito. Uno che ne riguarda il 29%,
concentrato su tre tipi contrattuali, produce un totale sbagliato del 24% senza
che nessuna commessa "normale" mostri un'anomalia.

È il motivo per cui questa regola andava presa da una fonte esterna: non c'era
modo di dedurla dai dati.

## 6. Gli storni non hanno una colonna

Il documento dice «costi consuntivati e storni». In `cm_projects` esiste
`actual_cost`, che è il consuntivo consolidato dal gestionale.

Ho assunto che gli storni siano già dentro. Se fossero esposti separatamente,
`- storni` va aggiunto alle formule B, C e D — non ad A, che non usa i costi.

L'assunzione è dichiarata nel commento della vista e nel deployment: è il genere
di cosa che, se sbagliata, sposta il risultato in modo sistematico.
