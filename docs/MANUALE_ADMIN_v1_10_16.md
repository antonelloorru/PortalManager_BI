# MANUALE AMMINISTRATORE — v1.10.16 · Diagnostica connessione sito web

1. **Aggiornare il plugin a 1.1.1** (WordPress › Plugin › Carica `pm-ats-1.1.1.zip` › Sostituisci). Con la 1.1.0 il sito si blocca con HTTP 429 dopo 20 chiamate in 15 minuti: è la causa più probabile dei test falliti.
2. PortalManager › Recruiting › **Sito web — Impostazioni** › **Diagnostica**. La tabella mostra per ogni passo il codice effettivo e il rimedio. Codici principali:
   - `cURL 60` → impostare File CA, es. `P:\xampp\apache\bin\curl-ca-bundle.crt` (dalla 1.10.16 viene cercato automaticamente);
   - `HTTP 401 bad_signature` → confrontare l'**impronta del segreto**: PortalManager (campo Segreto) e plugin (Impostazioni › Connessione) devono coincidere, altrimenti reincollare il codice di connessione;
   - `HTTP 401 unknown_client` → allineare il Client ID;
   - `HTTP 401 clock_skew` → sincronizzare l'orologio del server (`w32tm /resync`); la diagnostica mostra lo scarto;
   - `HTTP 403 ip_not_allowed` → aggiungere in «IP consentiti» del plugin l'IP mostrato («IP visto dal sito»). Dietro CDN impostare «Origine IP client»;
   - `HTTP 403` senza codice del plugin → firewall/WAF/CDN o plugin di sicurezza: consentire `/wp-json/pm-ats/`;
   - `HTTP 429 too_many_failures` → corretta la causa, attendere 15 minuti.
3. Nel plugin, Impostazioni › Connessione mostra anche l'**ultimo accesso rifiutato** con codice e IP.
