# CHANGELOG — v1.10.17 (2026-10-07)

Software 1.10.17 · Schema 1.10.17 · Upgrade `sql/migration_v1_10_17.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.1.1 (invariato)

## Diagnostica: `cURL 28 Failed to connect … port 443`
`cURL 28` in fase di connessione non è lentezza del sito: il pacchetto TCP non riceve risposta, quindi la richiesta non arriva mai a WordPress né al plugin. Aumentare il timeout non serve. Il messaggio ora lo dice esplicitamente, e la diagnostica aggiunge tre passi:

| Passo | Contenuto |
|---|---|
| 3b Rete | prova TCP sulla porta del sito e sulla 80 per ogni IP risolto (aperta / nessuna risposta / rifiutata, IP privato) |
| 3c Host alternativo | stesso test sul nome con/senza `www.`: spesso è pubblicato solo uno dei due |
| 3d Proxy | proxy di sistema (variabili d'ambiente, `netsh winhttp`). PHP/cURL **non** usa il proxy di Windows o del browser |

## IP forzato
Nuova impostazione **IP forzato** (Impostazioni › Rete e configurazione guidata › Opzioni), salvata in `wpats.resolve_ip` e applicata con `CURLOPT_RESOLVE`. Collega il nome host all'IP indicato mantenendo nome, SNI e verifica del certificato.

Serve quando il sito è ospitato nella rete aziendale e dall'interno l'IP pubblico non è raggiungibile (NAT hairpin), oppure con DNS interno.

## Altro
- `cURL 60/51` con nome non corrispondente al certificato: il rimedio indica di usare nell'URL il nome del certificato.
