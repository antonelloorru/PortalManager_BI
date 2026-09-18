# Manuale Amministratore — v1.8.91

## Gli incaricati sono ordinati per cognome

In tutte le schermate che elencano persone:

| Schermata | |
|---|---|
| Service Desk → Operatività per tecnico | ordinata |
| Service Desk → I componenti del Service Desk | ordinata |
| Relazione IT → tabella raggruppata per incaricato | ordinata |
| Relazione IT → menu di filtro «Incaricato» | ordinato |
| Export XLSX e report di stampa | seguono le stesse query |

**Il nome resta mostrato come prima** — «Enrico Mancini», non «Mancini Enrico».
Cambia l'ordine, non l'etichetta: le persone si riconoscono nella forma in cui il
gestionale le scrive.

## Perché non bastava un ordinamento semplice

Le due fonti scrivono il nome in ordini **opposti**:

- i moduli di intervento: `Marziali Matteo` — cognome per primo
- i ticket: `Enrico Mancini` — nome per primo

Un ordinamento sulla colonna avrebbe messo alcune persone in ordine di cognome e
altre in ordine di nome. Il risultato non sarebbe stato un elenco alfabetico ma un
rimescolamento apparentemente casuale.

Ho usato `cm_professionals`, che tiene nome e cognome in **colonne separate** ed è
completa su tutte e 247 le righe: è lì che si stabilisce quale delle due parole
sia il cognome, senza doverlo indovinare.

## Due casi delicati, entrambi verificati

**I cognomi composti** ordinano correttamente:

| Persona | Ordina sotto |
|---|---|
| Valentina **De Caprio** | **D**, non C |
| Massimiliano **De Battista** | **D** |
| Matteo **Di Fabio** | **D** |

L'euristica «l'ultima parola è il cognome» avrebbe prodotto «Caprio Valentina De».

**I nomi in maiuscolo**: `ZIN DANIELE` è scritto tutto maiuscolo in anagrafica, e
nel confronto fra stringhe le maiuscole precedono le minuscole — finiva in fondo
all'elenco, dopo tutti gli altri.

L'ho scoperto in collaudo scorrendo l'elenco fino in fondo: una sola anomalia su
quaranta, invisibile guardando le prime righe. Ora l'ordinamento ignora
maiuscole e minuscole.

## Un'eccezione voluta

I raggruppamenti **non per persona** — modalità, linea di servizio, settore —
restano ordinati **per ore decrescenti**.

Un elenco di modalità in ordine alfabetico costringerebbe a cercare quale pesa di
più; un elenco di persone ordinato per volume costringe a scorrere tutto per
trovare un nome. L'ordinamento giusto dipende da cosa si sta cercando.

## Chi non è in anagrafica

Compare comunque, ordinato per la propria stringa. Preferisco un ordinamento
imperfetto su pochi casi alla loro scomparsa dall'elenco: nessuno nota l'assenza
di una riga che non sa di dover cercare.
