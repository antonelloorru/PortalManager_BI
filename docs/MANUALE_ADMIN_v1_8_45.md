# Manuale Amministratore — v1.8.45

## Che cosa cambia

Fino a ieri le commesse arrivavano da un file esportato a mano dal gestionale e
caricato nel portale. Ora il portale può leggere direttamente dal database del
gestionale: si configurano una volta i parametri e la sincronizzazione si lancia
quando serve.

Il caricamento del file resta disponibile: le due strade convivono e scrivono
negli stessi campi.

## Prima di cominciare

Chiedete a chi amministra il gestionale un'**utenza dedicata di sola lettura**,
con il solo permesso di lettura sulla tabella dei contratti. Il portale non
scrive mai sulla sorgente e applica vincoli propri, ma i privilegi restano la
garanzia sostanziale.

Verificate inoltre che il server del portale raggiunga host e porta del
gestionale: la prova va fatta dal server, non dalla vostra postazione.

## Configurazione

**Gestione Commesse → Import Commesse DB**, riquadro *Connessione diretta*.

Compilate tipo di database, indirizzo, porta, nome del database, utente e
password. Scegliendo il tipo la porta si imposta da sola sul valore consueto.
Lo *schema* serve solo su alcuni database, tipicamente `dbo` su SQL Server; la
*tabella sorgente* è `contract` salvo diversa indicazione.

Premete **Salva parametri**.

La password viene salvata cifrata e non è più visibile. Quando tornerete sulla
pagina il campo apparirà vuoto: lasciandolo tale la password resta quella già
registrata, e va ricompilato solo per cambiarla.

## I tre passi successivi

**Prova connessione** verifica l'accesso e dice quali colonne attese ha trovato.
Se ne mancano di obbligatorie, tabella o schema indicati non sono quelli giusti.

**Anteprima** legge fino a 200 righe e mostra che cosa verrebbe inserito e che
cosa aggiornato. Non scrive nulla: serve a controllare prima di procedere.

**Sincronizza ora** esegue l'allineamento e riporta righe lette, nuove,
aggiornate, saltate e segnaposto assorbiti.

## Che cosa viene aggiornato

Codice, nome, cliente, stato, descrizioni, date, valore e valore a oggi,
consuntivato, margini, residui e anomalie. Lo stato del gestionale è tradotto:
OPEN diventa Aperta, CLOSED Chiusa, SUSPENDED Sospesa. L'azienda esecutrice è
dedotta dal prefisso del codice e il cliente viene creato in anagrafica se assente.

La scrittura avviene per codice commessa, quindi la sincronizzazione è ripetibile
quante volte serve: le commesse presenti vengono aggiornate, le nuove create, mai
duplicate.

## Le commesse eliminate

Le righe marcate come eliminate sul gestionale sono saltate. Se vi servono, la
casella *sincronizza anche le commesse marcate come eliminate* nei parametri le
include.

## Segnaposto DGB

La sincronizzazione riconosce i segnaposto `DGB-<numero>` creati
dall'integrazione DogoBit e li assorbe: i rapporti di intervento passano alla
commessa reale e il segnaposto sparisce. È lo stesso comportamento dell'import
XLSX introdotto con la versione 1.8.41, quindi il problema corretto allora non si
ripresenta per questa strada.

## Tracciabilità

Ogni sincronizzazione lascia traccia: la pagina mostra data e esito dell'ultima,
l'event log registra i conteggi e ogni commessa conserva il riferimento alla
sincronizzazione che l'ha scritta.

## Sicurezza

La password è cifrata con AES-256-GCM usando una chiave che risiede in
`.env.php`, fuori dal database: un backup del database non la espone. Non viene
mai rimandata al browser. La connessione è aperta in sola lettura e sono ammesse
esclusivamente istruzioni di lettura.

Attenzione: se il file `.env.php` venisse rigenerato, le password salvate non
sarebbero più decifrabili e andrebbero reinserite.
