# Manuale Amministratore — v1.8.67

## Anteprima su tutte le righe

Avete chiesto di poter esaminare tutti i dati e non solo 200 righe per tipo. Ora
c'è il pulsante **Anteprima integrale**.

Il limite di 200 non era una scelta di comodo: la procedura caricava le righe in
memoria, e il solo dataset delle allocazioni occupa 36 MB — l'insieme
supererebbe i 100 MB contro un limite tipico di 128 MB su XAMPP. Alzare il limite
avrebbe funzionato finché i dati non fossero cresciuti ancora.

La soluzione non è più memoria: la nuova anteprima **legge una riga alla volta
senza accumularle**. Occupa 6 MB indipendentemente dal volume, e può esaminare
tutto.

| Pulsante | Righe | Scrive | Tempo |
|---|---|---|---|
| Anteprima completa | 200 per tipo | no | secondi |
| **Anteprima integrale** | tutte | no | alcuni minuti |
| Sincronizza tutto | tutte | **sì** | alcuni minuti |

L'anteprima integrale riporta i conteggi **reali** di righe nuove e aggiornate,
non una proiezione. Se va in timeout, aumentate `max_execution_time` in
`php.ini`: la memoria non è un problema.

## La distribuzione sulle 24 ore

In **Attività & Rendicontazione DGB**, vista *Giorni (mese)*, sotto il grafico
compare una matrice: 24 righe per le ore del giorno, una colonna per ogni giorno
del mese. Più la cella è scura, più ore sono state lavorate in quella fascia.

Le ore in grassetto a sinistra sono le fasce ordinarie (09–13 e 14–18), le altre
reperibilità. Le colonne con intestazione chiara sono sabato e domenica.

### Un avvertimento sui numeri

Le ore sono **ripartite sulle fasce che l'intervento attraversa**, non attribuite
all'orario di inizio.

La differenza è grande. Contando per orario di inizio, l'ora 9 raccoglierebbe il
**64%** di tutte le ore — non perché il lavoro si concentri lì, ma perché quasi
ogni intervento viene registrato con inizio alle 09:00. Ripartendo, l'ora 9
scende al 13% e il profilo diventa quello vero: carico sostenuto dalle 9 alle 17,
crollo dalle 18, coda notturna.

Se confrontate questi numeri con un conteggio fatto per orario di inizio,
troverete valori molto diversi. Quelli della matrice sono quelli che descrivono
dove il lavoro è stato realmente svolto.

### Una verifica che dà confidenza

Le ore fuori fascia ordinaria risultano il **13,8%**. La classificazione
ordinario/reperibilità della v1.8.53 calcola il **13,48%**, per una via
completamente diversa.

Due misure indipendenti che convergono: è il tipo di riscontro che rende
utilizzabile un indicatore.

## Come leggerla

- **Bande scure orizzontali** fra le 9 e le 17: il lavoro ordinario
- **Celle isolate in alto o in basso**: interventi notturni
- **Colonne scure sotto le intestazioni chiare**: lavoro nel fine settimana
- **Colonne vuote nei feriali**: giornate di chiusura o di fermo

Passando il mouse su una cella compaiono giorno, ora e ore esatte.
