# Manuale Utente — v1.8.66

## Un aggiornamento tecnico del database

Alcuni aggiornamenti precedenti non erano riusciti a completare una pulizia dei
dati, a causa di un'incompatibilità tecnica fra tabelle.

L'incompatibilità è stata risolta e la pulizia è stata eseguita.

## I totali delle ore possono essere più bassi

Alcune registrazioni risultavano presenti due volte, e avrebbero dovuto essere
rimosse da diversi aggiornamenti fa: la rimozione non era riuscita.

Ora è stata completata, quindi **i totali di ore possono risultare inferiori** a
quelli che vedevate prima. Non si è perso nulla: erano righe contate due volte.

Da ora un controllo del database impedisce che la stessa prestazione venga
registrata più volte.
