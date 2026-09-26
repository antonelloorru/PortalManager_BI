# Deployment — v1.9.73
1. Estrarre lo ZIP nella root del portale (file .php in root, `app/`).
   I file già toccati da release precedenti sono inclusi nella loro versione più recente
   (bootstrap v1.9.70, footer v1.9.71, SyncRunner v1.9.72, menu/router/permessi cumulativi).
2. Eseguire `sql/migration_v1_9_73.sql`.
3. Sistema → Prestazioni → **Aggiorna ora**: dopo qualche minuto la tabella mostra, per
   ogni vista, il tempo di calcolo e se la copia è attiva.
4. Verifica dei tempi: Sistema → Prestazioni → **Attiva profiler**, poi aprire le quattro
   pagine e leggere il riepilogo in fondo (in rosso le query oltre 100 ms).

| Controllo | Atteso |
|---|---|
| Ordinativi Pratix con filtro Commerciale e ordinamento per Cliente | risposta immediata |
| Relazione di Servizio IT → Dettaglio per commessa | contratti chiusi, righe all'apertura |
| Relazione IT e Service Desk (periodo > 3 mesi) | card "Andamento giornaliero" |
| Service Desk / Report direzionale / Relazione IT | "dati aggiornati alle HH:MM" sotto il titolo |
| Sistema → Backup | il file `database.sql` contiene la sezione "Viste" |
