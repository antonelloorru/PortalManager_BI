# Manuale Amministratore — v1.8.78

## Il caso Mirko Vadi: causa trovata

Il modulo WTS_VAMI_26_019703 aveva **due allocazioni identiche** per lo stesso
operatore:

```
id 72423   9,00 h   4,00 extra   creata 17/07/2026
id 74450   9,00 h   4,00 extra   creata 13/08/2026
```

La giornata corretta somma 13 ore; il duplicato ne aggiunge 9, e il totale
diventa 22. I conti tornano esattamente.

## La causa: mancava un vincolo

`dgb_forms_activity_operator` non aveva un vincolo di unicità sulla combinazione
**attività + operatore**. Nulla impediva a una sincronizzazione di reinserire la
stessa allocazione con un nuovo identificativo.

## Su tutti i moduli

| | |
|---|---|
| Gruppi duplicati | **77** |
| Ore gonfiate | **362,50** |
| Con valori identici | **77 su 77** |

Sono copie, non revisioni. E **64 su 77 create il 13/08/2026**: una singola
risincronizzazione come origine — plausibilmente quella successiva
all'aggiornamento che ha portato le tabelle DGB nella sincronizzazione.

Il vincolo ora impedisce che si ripeta.

## Le ore extra sono dentro le ore

Come mi avete indicato: nelle 9 ore, 4 sono extra e 5 ordinarie. Il portale
sommava i due campi, contando le extra due volte. Corretto.

## Le nuove colonne

Trovate ora, per ogni allocazione e per ogni tecnico/giorno:

| Colonna | Significato |
|---|---|
| ore consuntivate | il totale |
| ore ordinarie | consuntivate meno extra — natura **contrattuale** |
| ore extra | straordinario dichiarato sul modulo |
| **ore in orario** | quante cadono nelle fasce 09–13 e 14–18 |
| **ore fuori orario** | tutte le altre |

**Le due coppie non vanno confuse.** Ordinarie/extra è contrattuale,
in/fuori orario è temporale: un intervento 09:00–19:00 ha 8 ore in orario e 1
fuori, indipendentemente da quante siano extra.

Il caso segnalato, ora:

| Modulo | Ore | Ordinarie | Extra | In orario | Fuori |
|---|---|---|---|---|---|
| 019706 + quattro brevi | 4,00 | 4,00 | — | 4,00 | — |
| **019703** (09:00–19:00) | 9,00 | 5,00 | 4,00 | 7,20 | 1,80 |
| **Totale** | **13,00** | 9,00 | 4,00 | 11,20 | 1,80 |

## Come consultarle

```sql
SELECT * FROM v_dgb_ore_tecnico_giorno
 WHERE giorno BETWEEN '2026-07-01' AND '2026-07-31'
 ORDER BY ore_fuori_orario DESC LIMIT 20;
```

Per il dettaglio modulo per modulo: `v_dgb_ore_dettaglio`.

## Il controllo da tenere

```sql
SELECT * FROM v_dgb_allocazioni_duplicate;
```

**Zero righe.** Il vincolo lo garantisce, ma la vista permette di verificarlo
dopo ogni sincronizzazione senza ricordare la query.

## Le ore caleranno di 362,5

È l'effetto voluto: erano righe contate due volte. Sul totale è lo 0,1%, ma sui
77 moduli interessati la differenza è del 50%.

**Fate un backup prima**: la migration elimina 77 righe.
