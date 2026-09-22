# Technical Design — PortalManager v1.8.87

## 1. Il ponte fra due nomenclature

`cm_sd_messages.author_name` viene da `dgb_operator` come `Nome Cognome`.
`cm_intervention_reports.technician_raw` porta `Cognome Nome`.

Un join diretto restituisce zero righe, e zero ore somiglia molto a «questa
persona non fa interventi». È il modo peggiore di sbagliare: nessun errore, un
dato plausibile e falso.

```sql
ON r.technician_raw = t.nome OR r.technician_raw = t.nome_invertito
```

`v_cm_sd_team` espone entrambe le forme dalla v1.8.82, quindi il ponte le usa
invece di ricostruirle. Vista e non colonna materializzata: l'inversione è una
caratteristica dei dati sorgente, e una colonna andrebbe riallineata a ogni
sincronizzazione.

## 2. Due attività, nessuna somma

Un ticket può generare un modulo di intervento. Il totale «attività» sarebbe
quindi un doppio conteggio parziale — parziale perché non tutti i ticket generano
moduli e non tutti i moduli nascono da ticket.

Non essendoci un legame esplicito fra le due tabelle, la sovrapposizione non è
nemmeno quantificabile. L'unica presentazione onesta è affiancarle:
intestazione a due livelli, bordo di separazione, e la dichiarazione sotto la
tabella.

Il caso di Bressi lo rende evidente: **meno ticket di tutti, più ore di tutti**.
Un indice sintetico lo avrebbe collocato in fondo alla classifica.

## 3. `has_revenue` riusato, non ricostruito

La distinzione fra commesse a ricavo e interne viene da `cm_contract_models`,
classificate nella v1.8.58.

`COALESCE(cm.has_revenue, 1)` tratta come a ricavo le linee non classificate:
è la scelta conservativa: contarle come interne gonfierebbe la quota di lavoro
non fatturabile, che è il numero su cui si prendono decisioni.

## 4. La quota sulla coda

```sql
ROUND(100 * tc.ticket / tot.ticket_coda, 1) AS quota_coda_pct
```

Il conteggio dei ticket per coda, da solo, non dice se il tecnico presidia la coda
o vi transita. Chiarini ha 117 ticket su *Sistemi* e 250 su *Supporto interno*:
sembrano due impegni comparabili, ma sono il 7,6% e il 60,0% delle rispettive
code.

Il denominatore è il totale dei ticket **distinti** della coda, non dei messaggi:
un tecnico prolisso avrebbe altrimenti una quota gonfiata.

## 5. Ore extra come colonna separata

`extra_hours` è **compreso** in `quantity_hours` (v1.8.78, indicazione
dell'azienda). L'indicatore lo mostra a parte con l'etichetta «di cui», e non
viene sommato al totale.

Averlo dichiarato nel titolo evita l'errore che la v1.8.78 aveva corretto nel
codice.

## 6. Quadrature verificate a tre livelli

| Livello | Verifica |
|---|---|
| totale | somma ore dei quattro = 11.908,5 = sorgente |
| per persona | ore a ricavo + interne = ore totali |
| per contratto | somma delle righe = totale della persona |

La terza è quella che intercetta gli errori di `GROUP BY`: se la chiave di
raggruppamento fosse incompleta, le righe si moltiplicherebbero e la somma
supererebbe il totale.

## 7. Il collaudo esegue le espressioni del template

Continuazione della v1.8.86: medie, percentuali, larghezze delle barre e quote
delle code vengono calcolate nel collaudo con gli avvisi convertiti in eccezioni.

Le verifiche sui limiti — barra oltre il 100%, quota coda oltre il 100% — colgono
gli errori di scala che non producono eccezioni ma un riquadro visibilmente
sbagliato.
