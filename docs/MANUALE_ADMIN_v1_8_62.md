# Manuale Amministratore — v1.8.62

## Il messaggio che avete visto era un falso allarme

*«Attenzione: mancano colonne obbligatorie (code, name)»* — la sorgente era a
posto, era la verifica a essere rimasta indietro.

Controllava **una sola tabella**, quella indicata nel parametro *tabella
sorgente*, cercandovi le colonne `code` e `name`. Era corretto nella v1.8.45,
quando la sincronizzazione leggeva davvero una tabella sola.

Oggi i dataset sono otto e leggono **quattordici tabelle** con join fra loro.
Quella verifica non diceva nulla di utile, e segnalava un problema inesistente.

Ho verificato sul vostro dump: `forms_contract` ha entrambe le colonne, e tutti
gli otto dataset funzionano correttamente.

## Cosa mostra ora la verifica

**Connessione al gestionale → Prova connessione**:

| | |
|---|---|
| Oggetti nello schema | 102 |
| Tabelle usate dai dataset | 14 |
| **Dataset utilizzabili** | **8 / 8** |
| Tabelle assenti | 0 |

E per ciascun dataset — commesse, costi fascia, full cost, professionisti,
tariffe, allocazioni, operazioni, rapporti — quante tabelle usa, quante colonne
produce e se funziona.

La verifica **esegue davvero** la query di ogni dataset, con `LIMIT 0`: non
trasferisce righe ma il server risolve tabelle, join e alias. È l'unico modo di
sapere che quella query funzionerà: un controllo sui soli nomi di colonna non si
accorge di un join verso una tabella rinominata.

## Se qualcosa non va

Il riquadro dice **quale** dataset e **perché**:

- *tabelle mancanti* — con il nome esatto della tabella assente
- *query non eseguibile* — con il messaggio di errore del server
- *colonne non prodotte* — la query gira ma i nomi non corrispondono

Provato simulando l'assenza di una tabella: 7 dataset su 8 restano utilizzabili e
l'errore viene attribuito al solo dataset che usa quella tabella.

**Importante**: se un dataset è in difetto, gli altri funzionano comunque. La
sincronizzazione completa prosegue su quelli validi. Non serve aspettare che
tutto sia perfetto per aggiornare il resto.

## Il parametro "tabella sorgente"

Non serve più correggerlo: qualunque valore abbia, la verifica controlla comunque
tutte e quattordici le tabelle. Resta nella configurazione solo per la parte
residua del vecchio flusso di import.
