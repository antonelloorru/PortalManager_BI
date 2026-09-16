# Manuale Amministratore — v1.9.5

## L'analisi del Team

In **Service Desk** compare il riquadro **Analisi del Team**, con sei indicatori:
moduli di intervento, ore, ore a ricavo, ore fuori orario, giornate-uomo e linee
di servizio.

Sotto, il **dettaglio della squadra** a intestazione doppia:

| | TICKET | MODULI DI INTERVENTO |
|---|---|---|
| per componente | presi in carico | moduli, ore, in orario, fuori, % fuori, giornate |

La percentuale fuori orario si evidenzia in rosso **sopra il 30%**.

## Ticket e moduli non si sommano

Sono due attività distinte. Un ticket **può** generare un modulo di intervento, ma
**non esiste una colonna che li leghi**: la sovrapposizione non è misurabile.

Sommarli produrrebbe un numero che non corrisponde a nulla, e nessuno saprebbe di
quanto sbaglia.

Se il gestionale esponesse il riferimento al ticket sul modulo, la somma
diventerebbe possibile — e sarebbe la prima cosa da fare.

## Le fasce orarie

**09–13 e 14–18 nei feriali**, il resto fuori orario. È la stessa regola della
Relazione di Servizio IT: se i due numeri divergessero, sarebbe un difetto nei
dati e non nella definizione.

**Sabato e domenica sono fuori orario per costruzione**, valutati prima ancora
dell'ora: un intervento domenicale alle 10 cadrebbe altrimenti nella finestra
09–13 e risulterebbe «in orario».

Verificato: un modulo del 20 giugno 2026 — sabato — risulta correttamente fuori
orario.

## «Non rilevata» è una terza categoria

Se un modulo non ha l'attività DGB collegata, l'ora di inizio manca.

Non l'ho assegnata né a «in orario» né a «fuori orario»: gonfiare o sgonfiare una
quota con casi ignoti avrebbe reso il numero inaffidabile senza dirlo.

**Se questa categoria cresce, il collegamento fra moduli e attività si sta
degradando**: è un indicatore utile.

## Contratto e fascia insieme

La tabella per tipologia di contratto espone anche le ore per fascia, e risponde a
una domanda che il conteggio separato non copre: **su quali contratti si lavora
fuori orario**.

Un canone erogato fuori orario costa più di quanto il canone preveda: è
l'informazione che ha un effetto pratico.

## Stampa ed export

Il **report di stampa** include quadro di squadra, dettaglio per componente e
ripartizione per contratto.

L'**export XLSX** ha tre fogli in più: Team, Fasce orarie, Contratti e fasce.

## Se il team risulta senza moduli

```sql
SELECT * FROM v_cm_sd_nome_moduli;
```

Attese quattro righe. Nei ticket il nome è `Nome Cognome`, nei moduli
`Cognome Nome`: senza il ponte il team risulterebbe con **zero ore**, che somiglia
molto a «questa squadra non fa interventi».
