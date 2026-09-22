# Technical Design — PortalManager v1.8.51

## 1. Un vincolo che non vincolava

La v1.8.50 chiudeva dichiarando che i due canali non allineati erano "protetti dal
vincolo". L'affermazione non è stata verificata prima di scriverla, ed era falsa.

```sql
UNIQUE KEY uq_ir_source_uid (source_uid)   -- su colonna NULLABLE
```

Lo standard SQL considera NULL come "valore sconosciuto", e due valori sconosciuti
non sono necessariamente uguali: un vincolo UNIQUE ammette quindi più righe con
NULL. È un comportamento corretto e documentato, ma controintuitivo — ed è lo
stesso meccanismo che aveva reso inefficace `uq_cir_dgb_source`, il vincolo
all'origine del difetto iniziale.

Lo stesso errore, due volte, sulla stessa tabella.

La v1.8.50 aveva inoltre rimosso `uq_report_code`, che pur essendo la chiave
sbagliata almeno bloccava il reinserimento identico. Il risultato netto: i due
canali non allineati sono passati dal duplicare in un caso particolare al
duplicare a ogni esecuzione.

## 2. Perché un trigger e non solo la correzione dei due file

Correggere i due importer sarebbe bastato per il difetto noto. Non per il
prossimo: qualunque nuovo canale — un endpoint API, uno script di migrazione, una
correzione manuale via SQL — potrebbe di nuovo omettere `source_uid`.

Il trigger sposta la garanzia dove non può essere dimenticata:

```sql
CREATE TRIGGER trg_ir_grana_ins BEFORE INSERT ON cm_intervention_reports
FOR EACH ROW SET NEW.source_uid = COALESCE(
    NULLIF(TRIM(NEW.source_uid), ''),
    CONCAT(SUBSTRING_INDEX(COALESCE(NEW.report_code,''), '/', 1), '#',
           COALESCE(CAST(NEW.technician_id AS CHAR),
                    CAST(NEW.technician_professional_id AS CHAR),
                    NULLIF(TRIM(NEW.technician_raw), ''),
                    '0')));
```

Un canale che fornisce `source_uid` lo mantiene; uno che non lo fornisce lo riceve
calcolato. In entrambi i casi il vincolo UNIQUE ha su cosa agire.

## 3. La catena di ripiego dell'identificativo tecnico

L'ordine è: `technician_id`, `technician_professional_id`, `technician_raw`, `'0'`.

I primi due sono le chiavi verso le due anagrafiche, interna ed esterna, ed è raro
che siano entrambe valorizzate.

Il terzo — il **nome** — è quello che rende il meccanismo utilizzabile. Nell'import
da file il tecnico è spesso noto solo per nome, e senza questo ripiego tutte le
prestazioni di un rapporto collasserebbero su `CODICE#0`: due tecnici diversi
avrebbero la stessa grana e il secondo verrebbe rifiutato dal vincolo. Verificato
in collaudo: due tecnici noti solo per nome producono due righe distinte.

Lo `'0'` finale è il caso residuo di rapporto senza alcun riferimento al tecnico.
Non è un valore valido ma un segnale, ed è contato nella vista di controllo come
`tecnico_non_identificato`: se cresce, il tracciato in ingresso ha un problema.

## 4. Stabilità della grana nel tempo

Il trigger su `UPDATE` ricalcola **solo** se `source_uid` è vuoto:

```sql
NEW.source_uid = COALESCE(NULLIF(TRIM(NEW.source_uid),''),
                          NULLIF(TRIM(OLD.source_uid),''),
                          <formula>)
```

La ragione è concreta. Una riga importata da file ha grana `COD#Rossi Mario`. Se
in seguito il tecnico viene riconosciuto e si valorizza `technician_id = 51`, una
formula rivalutata darebbe `COD#51`. Alla sincronizzazione successiva il canale
DGB cercherebbe `COD#51`, non lo troverebbe con la grana vecchia, e inserirebbe una
riga nuova.

Una chiave di grana che cambia non è una chiave. Verificato in collaudo:
aggiornando `technician_id` la grana resta invariata.

Il rovescio è che una riga nata senza identificativo conserva la grana basata sul
nome anche dopo il riconoscimento. È accettabile: la grana è un identificativo
tecnico, non un dato da leggere, e la coerenza nel tempo vale più della sua
eleganza.

## 5. Il codice inventato di DgbSync

```php
'DGB-' . (int)$r['id']    // id dell'allocazione, non codice del gestionale
```

Una seconda occorrenza del problema "codici non di riferimento", distinta dal
suffisso corretto in v1.8.50 e sfuggita a quella analisi perché riguardava un file
che non era stato aperto.

Ora si usa `a.code`, con ripiego sul vecchio schema solo se la sorgente non
espone il campo — così una sorgente incompleta non fa fallire la sincronizzazione.

## 6. Osservabilità per canale

`v_cm_grana_per_canale` raggruppa righe, grane distinte ed eccedenza per
`source_system`. L'eccedenza è la differenza fra le due: se è diversa da zero, quel
canale sta scrivendo più righe che grane, cioè sta duplicando.

Il valore diagnostico sta nell'attribuzione. Prima si vedeva che i totali erano
sbagliati; ora si vede **quale canale** li sta sbagliando, che è l'informazione
con cui si interviene.

## 7. Verifica con il codice reale

Le prove eseguono le query **estratte dai file di release**, non riscritte: la
`INSERT` di `import_intervention_reports.php` è prelevata dal sorgente e preparata
contro il database, e la funzione che calcola la grana è valutata dal file.

È l'unico modo di provare che quei file, così come verranno installati,
deduplicano. Una riscrittura per il test proverebbe che il test è corretto, non
che lo è il file.

Lo stesso metodo ha permesso di verificare la corrispondenza fra segnaposto e
parametri — 36 e 36 per l'import da file, 21 colonne per DgbSync — che un
conteggio a occhio sulle regex aveva dato per sbagliata.
