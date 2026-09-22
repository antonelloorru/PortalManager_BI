# Manuale Amministratore — v1.8.86

## I componenti del Service Desk

Sotto i quattro indicatori trovate la tabella di confronto. **Clic su un nome**
apre la scheda completa.

| Componente | Presi in carico | Risolti | Scalati | Escalation | 1ª risposta |
|---|---|---|---|---|---|
| Enrico Mancini | 613 | 582 | 31 | 5,1% | 9,2 h |
| Sebastiano Chiarini | 520 | 488 | 32 | 6,2% | 4,9 h |
| Emanuele Bressi | 278 | 248 | 30 | **10,8%** | 6,1 h |
| Greta Ferrante | 49 | 47 | 2 | 4,1% | 4,1 h |

## Perché «presi in carico» e non «messaggi»

Contare i messaggi misura **quanto una persona scrive**, non quanto risolve: chi
interviene a metà conversazione su un ticket già avviato accumula messaggi senza
esserne responsabile.

La misura attribuibile è **chi ha scritto la prima risposta**: quella persona ha
preso in carico il ticket, e l'esito le appartiene.

I 2.941 ticket con almeno una risposta hanno esattamente un responsabile ciascuno:
la somma della colonna *presi in carico* fa 2.941.

## La scheda ha due gruppi separati

| Gruppo | Cosa comprende | Attribuibile |
|---|---|---|
| **Ticket presi in carico** | quelli di cui ha scritto la prima risposta | **sì** |
| **Attività complessiva** | ogni messaggio, anche su ticket altrui | no |

Sono separati visivamente proprio perché è facile confonderli, e la conclusione
cambia parecchio.

## Leggere i numeri insieme, non isolati

Ogni indicatore di esito riporta la **media degli altri componenti**, perché un
valore isolato non dice se sia alto o basso.

**Il tempo di prima risposta va letto insieme al volume.** Mancini ha 9,2 ore
contro una media di 5,0 — ma prende in carico il doppio dei ticket di Bressi. Un
tempo senza il carico che lo accompagna descrive una persona lenta invece di una
persona occupata.

Il tasso di escalation compare in rosso quando supera del 40% la media: è una
**segnalazione da verificare**, non un giudizio. Bressi al 10,8% contro il 5,1% di
media può significare ticket più difficili, oppure una soglia di escalation più
bassa: il dato dice che vale la pena guardare, non che c'è un problema.

## Profili diversi, non rendimenti diversi

| Componente | Risposte | Note | Rapporto | Code |
|---|---|---|---|---|
| Chiarini | 1.243 | 315 | 3,9 | 11 |
| Bressi | 599 | 299 | 2,0 | 11 |
| Mancini | 1.277 | 753 | 1,7 | 11 |
| **Ferrante** | 69 | 108 | **0,6** | **5** |

Ferrante scrive più note che risposte e presidia 5 code contro 11. **È un ruolo
diverso**, non un rendimento inferiore.

Le misure sono affiancate e non sommate in un punteggio: un indice unico
confronterebbe persone che fanno lavori diversi, e la classifica che ne uscirebbe
sarebbe priva di significato operativo.

## Cosa contiene la scheda

- cinque indicatori di esito, con la media dei pari livello
- sei di attività, distinti visivamente
- **andamento mensile** a barre: risposte in blu, note interne in grigio
- **code presidiate**, con ticket e messaggi per ciascuna
- elenco dei **ticket presi in carico** nel periodo, con il tempo di prima
  risposta di ognuno

## Una nota sul periodo

La tabella di confronto usa l'**intero archivio**, non il periodo filtrato — è
scritto sotto la tabella. La scheda mostra invece entrambi: il totale e il periodo
selezionato nei filtri.
