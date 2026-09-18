# Manuale Amministratore — v1.9.14

## Il grafico ha ora una forma sola

L'andamento era disegnato in **tre modi diversi**:

| Dove | Prima | Ora |
|---|---|---|
| Riquadro a video | linee | linee |
| **Report di stampa** | **barre** | **linee** |
| **Scheda personale** | **barre** | **linee** |

Passando dalla schermata alla stampa il grafico cambiava forma senza che i dati
cambiassero natura.

## Perché linee

Le barre impilate affermano che le serie **si sommano in un totale**. Per i ticket
è vero, ma il riquadro mostra accanto il **tasso di escalation**, che è una
percentuale: impilarla sopra dei conteggi non produce nulla di interpretabile.

Le linee affermano meno — che ogni serie è una grandezza osservata nel tempo — ed
è un'affermazione sempre vera per questi dati.

## Le assenze restano a barre: è voluto

Ferie, permessi, recuperi e malattia **si sommano davvero** in un totale che ha
significato: le ore di assenza complessive.

Convertirle a linee avrebbe costretto a sommare a occhio quattro linee per leggere
il totale. La coerenza che serve è fra rappresentazioni della stessa grandezza,
non fra tutti i grafici del portale.

## Come è successo

Le tre resi nascevano da tre release diverse — v1.8.84, v1.8.86, v1.9.7 — e
ciascuna era ragionevole guardata da sola.

Il difetto è nato dal non aver mai guardato le tre insieme: nessuna delle tre
release aveva motivo di aprire le altre due.

## Una cosa trovata per caso

Nella scheda personale il colore delle note interne era leggermente diverso fra la
legenda e il grafico — due grigi vicini, differenza invisibile a occhio ma reale.

L'ho trovata solo perché la conversione mi ha fatto rileggere entrambi i punti. È
il genere di difetto che nessun controllo intercetta e nessun utente segnala.
