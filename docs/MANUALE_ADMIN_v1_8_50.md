# Manuale Amministratore — v1.8.50

## Le ore duplicate

Il portale poteva contare due volte le stesse ore. La causa: la tabella dei
rapporti aveva due chiavi di riconoscimento diverse e tre procedure di
importazione che ne usavano una a testa.

Una prestazione importata da file non veniva riconosciuta dalla sincronizzazione,
che ne creava una copia. Nessun controllo se ne accorgeva, perché i due codici
differivano per un suffisso e perché in un database un valore vuoto non collide
con un altro valore vuoto.

Nella situazione attuale **tutti i 67.723 rapporti** erano esposti: alla prima
sincronizzazione le 338.403 ore sarebbero raddoppiate.

### Come è stato risolto

È stata dichiarata una regola sola: **una riga = un tecnico su un intervento**.
A questa regola corrisponde un codice tecnico univoco che tutte e tre le
procedure ora usano, protetto da un vincolo del database.

La differenza è sostanziale: prima la correttezza dipendeva dal fatto che ogni
procedura si comportasse bene, ora è il database a impedire il doppio
inserimento. Una procedura che sbagliasse riceverebbe un errore invece di
scrivere silenziosamente una riga in più.

### Che cosa succede all'aggiornamento

La migration rimuove i duplicati già presenti, tenendo la registrazione più
vecchia di ciascuna prestazione. **Righe e ore possono diminuire**: è l'effetto
voluto, sono duplicati che c'erano.

Annotate i totali prima di aggiornare e confrontateli dopo con:

```sql
SELECT * FROM v_cm_grana_check;
```

Le ore non devono mai **aumentare**. Se accade, fermatevi e ripristinate il
backup.

## I codici di rapporto

I codici avevano un suffisso — `WTS_24_000123/21` — aggiunto per distinguere due
tecnici sullo stesso intervento. Cercando il codice del gestionale non si trovava
nulla.

Ora **il codice è identico a quello del gestionale**. La distinzione fra tecnici
è affidata a un identificativo interno che non compare nelle ricerche.

## I filtri

In Attività & Rendicontazione DGB i filtri *modalità* e *tipo report* agivano sui
totali in alto ma non sulla tabella sotto: applicando "da remoto" i numeri
scendevano e l'elenco restava uguale.

Ora agiscono su entrambi. Sui dati attuali "da remoto" porta l'elenco da 70.238 a
17.546 righe, coerentemente con i totali.

## Il menu riordinato

Quindici voci in ordine casuale, frutto della stratificazione delle release.
Riordinate secondo il flusso della rendicontazione:

**Anagrafiche** — le dimensioni su cui si aggrega: commesse, tecnici, unità,
professionisti, fasce di costo.

**Acquisizione dati** — ciò che porta i fatti: sincronizzazione, connessione,
import da file, riconciliazione.

**Analisi e rendicontazione** — ciò che legge le misure: attività DGB,
timesheet, carico, Gantt.

Due etichette sono cambiate perché erano fuorvianti: "Import Commesse DB" non
importa nulla, configura la connessione, ed è ora "Connessione al gestionale";
gli import da file lo dicono esplicitamente, per distinguerli dalla
sincronizzazione.

## Le viste analitiche

**`v_cm_rendicontazione`** è il punto unico da cui leggere i consuntivi. Espone
ogni prestazione con le sue dimensioni — commessa, cliente, tecnico, unità
organizzativa, mese — e le misure: ore pianificate, consuntivate, extra,
scostamento, ricavo, costo, margine.

Utile per estrazioni e per collegare strumenti esterni:

```sql
SELECT unita_organizzativa, anno_mese,
       SUM(ore_consuntivate) AS ore,
       SUM(margine)          AS margine
  FROM v_cm_rendicontazione
 WHERE anno = 2026
 GROUP BY unita_organizzativa, anno_mese;
```

Una avvertenza: la vista espone solo misure **sommabili**. Le percentuali non ci
sono di proposito, perché non si sommano né si mediano — la media delle
marginalità di dieci commesse non è la marginalità del portafoglio. Calcolatele
dopo l'aggregazione, dividendo due somme.

**`v_cm_grana_check`** è il controllo di integrità: se `duplicati` è diverso da
zero, i totali non sono affidabili finché non si indaga.
