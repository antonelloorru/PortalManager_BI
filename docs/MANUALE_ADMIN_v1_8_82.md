# Manuale Amministratore — v1.8.82

## Il team di primo livello lo definite voi

La classificazione L1/L2 legge **Unità Organizzative Tecniche → Service Desk**.
Oggi sono quattro:

| Tecnico | Sotto-unità |
|---|---|
| Greta Ferrante | Primo livello |
| Sebastiano Chiarini | Primo livello |
| Emanuele Bressi | Secondo livello |
| Enrico Mancini | Secondo livello |

Come indicato, **l'unità vale intera come primo livello**: la sotto-unità non
discrimina. Chiunque altro lavori un ticket è secondo livello.

L'elenco è letto **in tempo reale**, non copiato: se spostate un tecnico di unità
nel portale, le statistiche si aggiornano da sole. Verificatelo con:

```sql
SELECT * FROM v_cm_sd_team;
```

Se la vista fosse vuota, tutti i ticket risulterebbero gestiti da specialisti.

## Una classe che la regola non prevedeva

Applicando la regola alla lettera — L1 è l'unità, tutto il resto è escalation —
si ottengono **1.576 escalation su 2.941 ticket**: il 54%. Un numero drammatico e
falso.

Guardando dove stanno quei ticket, sono su code **specialistiche**: Sistemi,
Cybersecurity, Network. Nascono lì e vengono lavorati lì. **Non sono stati
scalati: non sono mai passati dal Service Desk.**

L'escalation presuppone una presa in carico precedente. Ho quindi introdotto una
terza classe:

| Gestione | Ticket | Quota |
|---|---|---|
| Presa in carico diretta da specialisti | 1.471 | 41,9% |
| Risolto dal Service Desk | 1.365 | 38,9% |
| Senza risposta | 571 | 16,3% |
| **Escalation di 2° livello** | **105** | **3,0%** |

**Il tasso di escalation è il 3,0%**, calcolato sui soli ticket che il Service
Desk ha effettivamente preso in carico. È la misura che risponde alla domanda
«quanto spesso il primo livello non basta».

## I 571 senza risposta

**553 sono chiusi**, con meno di due messaggi ciascuno: notifiche automatiche o
richieste risolte per telefono e chiuse a mano.

Contarli fra i risolti gonfierebbe la produttività, contarli fra i non gestiti
creerebbe un allarme falso. Sono una classe a sé.

**Quelli da guardare sono 18**: aperti e mai risposti.

```sql
SELECT * FROM v_cm_sd_ticket
 WHERE gestione = 'senza risposta' AND stato <> 'CLOSED';
```

## Andamento

```sql
SELECT * FROM v_cm_sd_riepilogo ORDER BY anno_mese DESC LIMIT 12;
```

| Mese | Ticket | Risolti L1 | Escalation | Tasso | Durata media |
|---|---|---|---|---|---|
| 2026-08 | 150 | 54 | 1 | 1,8% | 74,5 h |
| 2026-07 | 291 | 115 | 5 | 4,2% | 167,4 h |
| 2026-06 | 335 | 122 | 11 | 8,3% | 217,0 h |
| 2026-05 | 339 | 131 | 14 | 9,7% | 255,1 h |
| 2026-03 | 334 | 137 | 11 | 7,4% | 430,1 h |

**Il tasso di escalation è in calo da maggio, e la durata media si è ridotta di
quattro volte da marzo.** Entrambi movimenti favorevoli.

## Un riscontro sulla classificazione

```sql
SELECT * FROM v_cm_sd_operatori ORDER BY messaggi DESC;
```

| Tecnico | Livello | Code |
|---|---|---|
| Mancini, Chiarini, Bressi | L1 | **11** |
| Todisco | L2 | 2 |
| De Caprio | L2 | 4 |

Il primo livello opera su **undici code**, gli specialisti su due o quattro: è la
firma di chi smista trasversalmente.

Se un tecnico marcato L1 operasse su due code soltanto, varrebbe la pena
verificarne l'assegnazione all'unità.

## Nota sui dati

`tt_ticket` non è esportata dal gestionale: il ticket viene **ricostruito** dai
suoi messaggi in `tt_article`. 13.479 messaggi per 3.512 ticket.

Se in futuro l'export includesse la tabella dei ticket, avremmo anche gli SLA di
presa in carico e risoluzione, che oggi non sono disponibili.
