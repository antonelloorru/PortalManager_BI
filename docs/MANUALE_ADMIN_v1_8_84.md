# Manuale Amministratore — v1.8.84

## La sezione Service Desk

**Gestione Commesse → Service Desk**. Quattro indicatori in testa:

| Indicatore | Cosa dice |
|---|---|
| **Ticket del periodo** | volume, quanti chiusi, messaggi medi |
| **Presi in carico da L1** | risolti più scalati dal Service Desk |
| **Tasso di escalation** | quanto spesso il primo livello non basta |
| **Da presidiare** | ticket che richiedono un intervento **oggi** |

Sull'intero periodo: 3.512 ticket, 1.470 presi in carico, **7,1% di escalation**,
**14 da presidiare**.

## Il tasso di escalation: attenzione al denominatore

È calcolato sui ticket **presi in carico dal Service Desk**, non sul totale.

I 1.471 ticket nati su code specialistiche — il 42% — non vi rientrano: non sono
mai passati dal primo livello, quindi non possono essere stati scalati.

Includerli porterebbe il tasso dal **7,1% al 3,6%**. Sarebbe un numero più
lusinghiero, e descriverebbe una realtà diversa da quella misurata.

Il pannello riporta sotto la tabella il numero dei ticket su cui il tasso è
calcolato, così il denominatore è sempre visibile.

## Le sei classi, con i colori giusti

Le tre situazioni «senza risposta» hanno **colori distinti** perché hanno
significati opposti:

| Classe | Colore | Azione |
|---|---|---|
| Lavorato senza risposta scritta | grigio | nessuna: risolto per altra via |
| Cliente senza risposta scritta | ambra | verificare quelli aperti |
| **Mai preso in carico** | **rosso** | **intervenire** |

Un gradiente di grigi le avrebbe fatte leggere come varianti della stessa cosa —
l'errore che avevamo appena corretto scomponendo l'aggregato dei 571.

## Il grafico ha due scale

I ticket vanno da 150 a 423 al mese, il tasso da 1,8% a 9,7%. Su una scala comune
il tasso sarebbe una linea piatta.

L'asse **sinistro** è dei ticket, il **destro** del tasso in percentuale. La serie
tratteggiata è quella con la scala destra.

## Ticket da presidiare

Ordinati per anzianità, in rosso sopra i 30 giorni. L'ultima colonna dice **chi ha
toccato il ticket**: se indica `nessuno`, in rosso, non lo ha guardato nessuno.

Il più vecchio nel vostro archivio è aperto da 210 giorni.

## Il numero di code come verifica

Nella tabella per tecnico, la colonna **code** distingue i ruoli: il primo livello
opera su 11 code, gli specialisti su 2–4.

È un riscontro indipendente: se un tecnico marcato L1 comparisse su due code
soltanto, varrebbe la pena verificarne l'assegnazione all'unità.

## Se compare l'avviso rosso sul team

*«Nessun tecnico assegnato all'unità Service Desk»*: senza assegnazione ogni
ticket risulta gestito da specialisti, e il tasso di escalation sarebbe zero — un
numero che sembra ottimo e non significa nulla.

Si corregge in *Unità Organizzative Tecniche*, e **la modifica si riflette
subito**: la classificazione legge l'unità in tempo reale, non una copia.

## Filtri ed export

Periodo, coda, livello coinvolto. Il filtro **livello** seleziona i ticket
*toccati* da quel livello: uno stesso ticket può comparire sia con L1 sia con L2
se entrambi vi hanno lavorato.

Export XLSX con quattro fogli: ripartizione, andamento, da presidiare, operatori.
