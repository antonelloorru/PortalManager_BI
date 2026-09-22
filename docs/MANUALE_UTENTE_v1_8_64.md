# Manuale Utente — v1.8.64

## La sincronizzazione funziona di nuovo

L'aggiornamento dei dati dal gestionale falliva su tutti i tipi di dato. La causa
era un difetto tecnico: quando il primo aggiornamento non riusciva, tutti quelli
successivi si bloccavano a catena.

Ora un tipo di dato che non riesce **non blocca gli altri**: l'aggiornamento
prosegue e alla fine viene indicato quale ha avuto problemi.

## Cosa fare

Aprite **Sincronizzazione gestionale** e premete *Sincronizza tutto*. Al termine
la tabella deve mostrare tutti gli undici tipi di dato con esito **ok**.

Se qualcuno risulta in errore, il motivo è indicato accanto: gli altri dati sono
comunque stati aggiornati.
