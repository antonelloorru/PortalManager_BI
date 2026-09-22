# Manuale Amministratore — v1.9.1

## Quanto cambia, sui vostri dati

**1.092 commesse, copertura 98,9%.**

| Formula | Commesse | Margine attuale | Corretto | Scarto |
|---|---|---|---|---|
| A | 6 | 310.058 | 307.799 | −2.259 |
| **B** | **775** | 15.361.134 | 15.361.134 | **0** |
| **C** | 143 | 2.548.444 | **9.787.503** | **+7.239.059** |
| **D** | 168 | 4.299.557 | **2.419.780** | **−1.879.777** |
| **TOTALE** | 1.092 | **22.519.194** | **27.876.216** | **+5.357.022** |

**Il margine complessivo è sottostimato di 5,36 milioni — il 23,8%.**

## Dove si concentra

| Tipo | Commesse | Attuale | Corretto | Scarto |
|---|---|---|---|---|
| **Presidio** | 50 | 3.763.893 | 9.575.010 | **+5.811.117** |
| **Contr. Servizi Scalare** | 168 | 4.299.557 | 2.419.780 | **−1.879.778** |
| Servizio Gestito SOC | 16 | 1.174.630 | 2.571.672 | +1.397.042 |

**Il Presidio vale da solo l'intera differenza positiva**: 50 commesse il cui
margine è oggi sottostimato di 5,8 milioni, perché il valore consuntivato viene
sottratto invece che sommato.

I **Contratti a Scalare** vanno nella direzione opposta, −1,88 milioni: si usa il
valore contrattato dove conta il consumato.

Non si compensano per caso: sono due errori opposti su tipi contrattuali diversi.

## Perché nessuno se n'era accorto

**775 commesse su 1.092 — il 71% — erano già calcolate correttamente**: la formula
B coincide con quella che il portale applicava a tutte.

Un errore che riguarda il 29% dei casi, concentrato su tre tipi contrattuali,
produce un totale sbagliato del 24% **senza che nessuna commessa "normale" mostri
un'anomalia**. Non c'era modo di dedurlo dai dati: serviva il vostro documento.

## Non ho sostituito i numeri

Le viste che alimentano i pannelli **continuano a calcolare come prima**.

Cambiarli in questa release avrebbe fatto muovere ogni cruscotto da un giorno
all'altro, e reso impossibile riconciliare un report stampato la settimana
precedente.

Trovate le due colonne affiancate:

```sql
SELECT commessa, cliente, tipo_contratto, formula,
       margine_attuale, margine, scarto_formula
  FROM v_cm_margine_formula
 WHERE ABS(scarto_formula) > 10000
 ORDER BY ABS(scarto_formula) DESC LIMIT 30;
```

Verificate commessa per commessa da dove viene la differenza. Quando avrete
deciso, il passaggio è una modifica circoscritta che preparo io.

## Una domanda sugli storni

Il documento dice «costi consuntivati **e storni**». Ho usato
`cm_projects.actual_cost`, assumendo che gli storni siano già compresi nel
consuntivo consolidato dal gestionale.

**Se fossero esposti in una colonna separata**, vanno sottratti esplicitamente e
il risultato cambia. Ditemi dove si trovano e aggiorno le viste.

## Il margine percentuale

Su un contratto a scalare la percentuale si rapporta al **consuntivato**, non al
plafond: rapportarla al plafond darebbe un numero che scende man mano che il
contratto viene consumato, anche a redditività costante.
