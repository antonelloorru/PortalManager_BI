# MANUALE AMMINISTRATORE — v1.10.17 · Sito non raggiungibile (cURL 7 / cURL 28)

Se la diagnostica riporta «3 Trasporto / TLS — cURL 28 Failed to connect … port 443», la richiesta non raggiunge il sito. Leggere i passi 3b-3d:

1. **3d Proxy «di sistema»**: il server esce tramite proxy. Indicarlo in Impostazioni › Rete › *Proxy in uscita* (es. `proxy.azienda.local:8080`).
2. **3c Host alternativo risponde**: il WordPress è sull'altro nome (con o senza `www`). Usare l'URL API mostrato dal plugin in *Impostazioni › Connessione*.
3. **3b nessuna porta risponde, IP pubblico dell'azienda**: il sito è ospitato nella rete aziendale e il firewall non consente di raggiungere l'IP pubblico dall'interno (NAT hairpin). Impostare *IP forzato* con l'IP interno del server web: il certificato resta verificato sul nome.
4. **3b nessuna porta risponde, sito esterno**: aprire in uscita la porta 443 dal server PortalManager verso l'IP del sito.

Verifica da riga di comando sul server PortalManager: `powershell Test-NetConnection www.sito.it -Port 443` (TcpTestSucceeded deve essere True).
