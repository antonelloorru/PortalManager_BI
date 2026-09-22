# Technical Design — PortalManager v1.9.4

## 1. Una closure invece di un metodo

`$registraEsecuzione` è definita in `sync_commesse.php` e usata dai tre punti che
concludono una sincronizzazione.

Un metodo in `DatasetSync` sarebbe stato più ordinato, ma quella classe non sa
nulla della pianificazione: le passa i dataset e riceve i conteggi. Introdurvi una
dipendenza da `cm_sync_schedule` l'avrebbe legata a una funzionalità che non le
appartiene.

La closure sta dove la decisione viene presa, e i tre chiamanti la vedono.

## 2. Tre stati e non due

Il caso da evitare era preciso: `cron_sync.php` salta la giornata quando trova
`last_status` fra `ok` e `parziale`.

Se un import di un singolo dataset avesse scritto `parziale`, avrebbe **sospeso la
sincronizzazione automatica di quel giorno** — silenziosamente, e con l'apparenza
di aver fatto qualcosa di utile.

`dataset` è fuori da quell'elenco. Non ha richiesto di modificare il cron, che
continua a leggere gli stessi due valori: è la scelta del valore scritto a
risolvere il problema, non una condizione aggiunta.

Lo stesso vale per il riquadro in home (v1.8.76), che filtra sugli stessi due
stati.

## 3. Il lock resta al cron

Prendere il lock durante una sincronizzazione manuale sarebbe stato coerente —
protegge dalla sovrapposizione — e sbagliato.

Il lock scade dopo tre ore. Una pagina che lo prende e viene chiusa a metà
lascerebbe il cron bloccato fino alla scadenza, per un'esecuzione che nessuno sta
più seguendo.

Il rischio accettato è che una sincronizzazione manuale e una automatica si
sovrappongano. È raro — il cron gira a orari prestabiliti — e il danno è una
doppia lettura, non una corruzione: `writeRows` usa `INSERT ... ON DUPLICATE KEY`.

## 4. Il fallimento silenzioso è voluto

```php
} catch (Throwable $e) {
    // le tabelle della v1.8.75 potrebbero non esserci
}
```

Se `cm_sync_schedule` non esiste, la sincronizzazione è comunque avvenuta: i dati
sono aggiornati e i conteggi mostrati.

Sollevare qui avrebbe fatto apparire fallita un'operazione riuscita, per un
difetto in una funzione accessoria.

## 5. `$t0` mancante: il difetto che il collaudo ha trovato

La durata serve alla registrazione, e `$t0` era definito solo in `sync_all`.

`php -l` non lo vede: una variabile non definita è sintatticamente valida, e in
PHP produce un avviso e il valore `null` — che qui sarebbe diventato una durata
negativa di quarantasei anni.

Il controllo scritto per trovarlo isola i blocchi `$action === '…'` e verifica che
ogni blocco che invoca `registraEsecuzione` definisca `$t0` al proprio interno.

È lo stesso genere di difetto della v1.8.72 (`$NAT` usata prima della
definizione), e il controllo di allora — che eseguiva il rendering — non lo
avrebbe visto: questi blocchi sono nella parte POST della pagina, non nel
template.

## 6. `trigger_type` come dimensione, non come etichetta

Registrare l'origine costa una colonna già esistente e permette una domanda che
altrimenti non si può porre: **la pianificazione funziona, o qualcuno la supplisce
a mano ogni giorno?**

Senza la distinzione, un registro pieno di esecuzioni riuscite sembrerebbe la
prova che tutto va bene, mentre potrebbe essere la prova che qualcuno sta
lavorando al posto del sistema.
