# MANUALE AMMINISTRATORE — v1.10.08

## DB SOC con le credenziali del gestionale
Commesse › Sincronizzazione gestionale › tab **SOC** › Sincronizzazione dal DB SOC › Connessione (Super Admin).

1. Spuntare **Usa server e credenziali della Connessione al gestionale**: driver, host, porta, utente e password diventano di sola lettura (riepilogo del gestionale sopra i campi).
2. **Database**: nome del database SOC sullo stesso server.
3. Prefisso ticket, finestra, query: invariati.
4. **Salva** → **Test connessione**: atteso «Connessione riuscita … utente@host/database (credenziali del gestionale)».

Prerequisito: Commesse › Importa da gestionale › connessione salvata, attiva e funzionante.
Cambio password del gestionale: aggiornarla solo nella Connessione al gestionale, vale anche per il SOC.

## Errori
| Messaggio | Azione |
|---|---|
| `[1045]`/`[1698] Access denied … (using password: YES)` | Credenziali del gestionale: verificarle con Test connessione nel gestionale. Credenziali proprie: attivare l'eredità o reinserire la password. |
| `[1044] Access denied … to database 'x'` | Sul server: `GRANT SELECT ON \`x\`.* TO 'utente'@'host_portale';` |
| `[1049] Unknown database` | Nome database errato. |
| «usa server e credenziali della Connessione al gestionale, che non è configurata» | Configurare o attivare la Connessione al gestionale. |
