# Manuale Amministratore — v1.8.51

## Perché questa release è urgente

La versione precedente aveva corretto la duplicazione delle ore per le procedure
di importazione aggiornate, lasciandone fuori due: l'import dei rapporti da file
e la sincronizzazione DGB. La nota di rilascio diceva che erano comunque protette
dal nuovo controllo.

**Non lo erano.** Il controllo si basava su un campo che quelle due procedure non
compilavano, e un campo vuoto non fa scattare il controllo. In più la v1.8.50
aveva rimosso il vecchio controllo, che pur essendo sul campo sbagliato almeno
bloccava i reinserimenti identici.

Risultato misurato: con la v1.8.50 installata, tre esecuzioni dello stesso import
producevano **tre righe e 24 ore al posto di 8**.

Se avete installato la v1.8.50, applicate questa release **prima del prossimo
import o sincronizzazione**.

## Come è stato risolto

Il calcolo dell'identificativo di riga è stato spostato **dentro il database**,
con un meccanismo automatico che si attiva a ogni inserimento.

La differenza è che ora non dipende più da quali procedure sono state aggiornate:
vale per tutte, comprese quelle che verranno aggiunte in futuro e le correzioni
fatte a mano. Una procedura che dimenticasse di compilare il campo lo riceve
calcolato.

L'identificativo combina il codice del rapporto con il tecnico. Quando il tecnico
è noto solo per nome — il caso tipico dell'import da file — viene usato il nome:
due tecnici diversi sullo stesso rapporto restano quindi distinti.

## Un cambiamento visibile: i codici DGB

La sincronizzazione scriveva codici come `DGB-4521`, inventati dal portale.
Cercando il codice del gestionale non si trovava nulla.

Ora scrive il codice reale. I rapporti già presenti conservano il vecchio codice
finché non vengono risincronizzati: per allinearli tutti, eseguite una
sincronizzazione completa dopo l'aggiornamento. Le righe esistenti vengono
riconosciute e aggiornate, non duplicate.

## I due controlli da guardare

```sql
SELECT * FROM v_cm_grana_check;
```

`duplicati` e `senza_grana` devono essere **0**.

```sql
SELECT * FROM v_cm_grana_per_canale;
```

Mostra righe e ore per provenienza — import da file, sincronizzazione DGB,
inserimento manuale. La colonna **`eccedenza` deve essere 0 su ogni riga**: se non
lo è, quel canale sta scrivendo più righe che prestazioni reali.

È la differenza rispetto a prima: non si vede solo che i totali sono sbagliati, si
vede **quale procedura** li sta sbagliando.

## La verifica che conta

Eseguite due volte di seguito lo stesso import, o due sincronizzazioni consecutive
senza modifiche sul gestionale. Righe e ore devono restare identiche.

Prima di questa release il totale cresceva a ogni ripetizione.

## Nota tecnica

La migration installa due trigger su `cm_intervention_reports` e richiede il
privilegio `TRIGGER`. Su XAMPP con utenza `root` non è un problema; se il SQL
Runner segnalasse *access denied*, eseguite la migration da phpMyAdmin.

Verifica:

```sql
SHOW TRIGGERS LIKE 'cm_intervention_reports';
```

Devono comparire `trg_ir_grana_ins` e `trg_ir_grana_upd`. Senza di essi la
protezione non è attiva.
