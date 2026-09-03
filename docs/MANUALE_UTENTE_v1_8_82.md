# Manuale Utente — v1.8.82

## Statistiche del Service Desk

Il portale importa ora i ticket dal sistema di ticketing: **3.512 ticket** e
13.479 messaggi.

Per ciascun ticket vengono ricostruiti: oggetto, coda, stato attuale, numero di
messaggi, data di apertura e ultimo movimento.

## Primo e secondo livello

I tecnici assegnati all'unità **Service Desk** in *Unità Organizzative Tecniche*
sono il primo livello. Chiunque altro lavori un ticket è secondo livello.

Ogni ticket viene classificato in una di quattro situazioni:

- **risolto dal Service Desk** — solo il primo livello vi ha lavorato
- **escalation di 2° livello** — iniziato dal Service Desk e passato a specialisti
- **presa in carico diretta da specialisti** — il Service Desk non è mai
  intervenuto, il ticket è nato su una coda specialistica
- **senza risposta** — nessuna risposta scritta al cliente

## Attenzione al tasso di escalation

Il tasso indicato — attualmente il 3% — è calcolato **solo sui ticket che il
Service Desk ha preso in carico**.

I ticket che nascono direttamente sulle code specialistiche non entrano nel
conteggio: non sono stati scalati, semplicemente non sono mai passati dal primo
livello.
