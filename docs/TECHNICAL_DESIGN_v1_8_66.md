# Technical Design — PortalManager v1.8.66

## 1. Una collation ereditata dal posto sbagliato

```sql
CREATE TABLE tmp_ir_dupes (
    keep_id int(11) NOT NULL,
    source_uid varchar(120) NOT NULL,
    …
) ENGINE=InnoDB;
```

Senza `COLLATE`, la tabella eredita quella di default del **database**. Sembra
ragionevole, e lo è finché tutte le tabelle seguono quel default.

`cm_intervention_reports` non lo seguiva. Una sola tabella divergente basta a far
fallire ogni confronto che la coinvolga.

## 2. Ereditare invece di dichiarare

```sql
CREATE TABLE tmp_ir_dupes66 AS
SELECT MIN(id) AS keep_id, source_uid
  FROM cm_intervention_reports
 WHERE source_uid IS NOT NULL AND source_uid <> ''
 GROUP BY source_uid;
```

`CREATE TABLE ... AS SELECT` deriva tipo e collation dalla colonna sorgente.
Verificato: su una tabella `general_ci`, la colonna della temporanea risulta
`general_ci`.

L'alternativa era cablare `COLLATE utf8mb4_general_ci`. Avrebbe risolto qui e
rotto sull'installazione opposta — un difetto sostituito dal suo simmetrico. Qui
la collation non viene scelta: viene ereditata, quindi è corretta per costruzione.

L'indice si aggiunge dopo, con `ALTER TABLE`, perché `CREATE ... AS SELECT` non
accetta la definizione di chiavi.

## 3. Il problema non erano le due DELETE

La segnalazione riportava due errori, ed è stato naturale pensare a un difetto
locale. Riprodurre lo scenario — tabella `general_ci` in database `unicode_ci` —
ne ha mostrati **sei**.

Le altre quattro non erano state segnalate perché il SQL Runner mostra i primi
errori, e perché riguardavano statement più a valle che l'utente non aveva ancora
raggiunto.

È il motivo per cui vale la pena riprodurre uno scenario invece di correggere
solo ciò che è stato riportato: la segnalazione è un campione, non l'inventario.

## 4. La correzione va dove sta l'anomalia

Due strade:

1. `COLLATE` esplicito su ogni join che tocca `cm_intervention_reports`;
2. allineare la collation della tabella.

La prima corregge sei statement e lascia il problema: ogni query futura dovrebbe
ricordarsene, e la settima dimenticherebbe.

La seconda rimuove la causa. `cm_intervention_reports` è **l'unica** tabella
divergente — verificato interrogando `information_schema` — quindi la conversione
riguarda un solo oggetto e riporta il database a uno stato uniforme.

```sql
ALTER TABLE cm_intervention_reports
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Target `unicode_ci` perché è la collation dichiarata in tutte le `CREATE TABLE`
delle migration e quella di default del database: si allinea la minoranza alla
maggioranza, non viceversa.

## 5. L'ordine è la correzione

Il primo tentativo metteva l'`ALTER` nel blocco v1.8.66, cioè in fondo al
consolidato. Il collaudo ha mostrato gli stessi sei errori: gli statement che
falliscono stanno al #337 e al #404, e l'`ALTER` arrivava al #479.

Spostato in **testa al file**, prima di qualunque statement. Il consolidato passa
da 6 errori a zero.

È un dettaglio che nessuna analisi statica avrebbe rivelato: la correzione era
giusta, la posizione no, e solo l'esecuzione lo ha mostrato.

Su un'installazione da zero la tabella non esiste ancora e l'`ALTER` fallisce in
modo innocuo — verrà creata più avanti con la collation corretta.

## 6. Il vincolo mancante

Le migration v1.8.50 e v1.8.51 si interrompevano sulla `DELETE`, che precede
l'`ADD UNIQUE KEY`. Quindi né la deduplica né il vincolo erano stati applicati:
la protezione contro i duplicati, oggetto di due release, non era mai entrata in
funzione.

Questa migration esegue entrambi. È anche la ragione per cui le ore consuntivate
possono calare dopo l'aggiornamento: i duplicati sono lì da tre release.

## 7. Il controllo permanente

`v_cm_collation_check` elenca le colonne di collegamento con collation diversa da
quella del database.

Zero righe non garantisce che tutto funzioni, ma una riga garantisce che qualcosa
prima o poi fallirà. È il tipo di controllo che vale la pena avere: costa una
vista e trasforma un errore in fase di migration in una verifica preventiva.
