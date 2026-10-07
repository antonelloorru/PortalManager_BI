# CHANGELOG — v1.10.16 (2026-10-07)

Software 1.10.16 · Schema 1.10.16 · Upgrade `sql/migration_v1_10_16.sql` (pacchetto cumulativo da 1.10.06) · Plugin WordPress pm-ats **1.1.1**

## Causa reale del fallimento del test di connessione
WordPress richiama il `permission_callback` di una rotta **due volte** per richiesta: una nel dispatch e una in `rest_send_allow_header()`, il filtro `rest_post_dispatch` che compone l'intestazione `Allow`.

Con il plugin 1.1.0 la seconda verifica HMAC trovava il nonce già usato. Quindi:
- registrava `replay` e incrementava il contatore dei fallimenti per IP **anche sulle chiamate riuscite**;
- dopo 20 chiamate in 15 minuti (bastano pochi test o un prelievo di candidature con i CV) il sito rispondeva **`429 too_many_failures`** e test e sincronizzazioni fallivano per 15 minuti;
- gli errori reali (401/403) venivano contati due volte e bloccavano il sito ancora prima.

Riprodotto con il plugin 1.1.0: 20 test riusciti, dal 21° `HTTP 429`. Con la 1.1.1: 25 test e 12 sincronizzazioni complete su 12 riusciti, nessun `replay`.

Correzione (plugin 1.1.1): l'esito della verifica è memorizzato per oggetto richiesta, quindi la seconda invocazione lo riusa senza effetti collaterali.

## Codice d'errore effettivo
Prima l'interfaccia mostrava un elenco generico («401 firma/orologio, 403 IP, SSL…»). Ora il test e la nuova **Diagnostica dell'handshake** mostrano il codice reale e il rimedio:

| Origine | Codice mostrato | Esempio |
|---|---|---|
| Rete / TLS | `cURL <errno>` | `cURL 60` CA mancante → File CA; `cURL 6` DNS; `cURL 7` connessione; `cURL 28` timeout; `cURL 35` handshake TLS |
| Redirect | `HTTP 301/302` | URL finale suggerito (https, www) |
| Plugin pm-ats | `HTTP <stato> <motivo>` | `401 bad_signature` (con la rotta vista dal sito), `401 unknown_client`, `401 clock_skew` (scarto in secondi), `403 ip_not_allowed` (IP visto dal sito), `429 too_many_failures`, `503 not_configured` |
| WordPress | `HTTP <stato> rest_*` | `404 rest_no_route` (plugin non attivo), `401 rest_not_logged_in` / `rest_cannot_access` (plugin di sicurezza) |
| Intermediario | `HTTP <stato>` | 403 HTML di Cloudflare/WAF, 503 di una pagina di manutenzione, con titolo della pagina e server |

## PortalManager
- `app/WpAtsDiag.php`: diagnostica in 6 passi.
  - Passi: configurazione (impronta del segreto), DNS, trasporto/TLS, plugin presente, autenticazione firmata, orologio (intestazione Date).
  - Storico nella nuova tabella `wp_ats_diag`.
- `WpAtsClient`:
  - `analyze()` e `describe()` restituiscono il codice effettivo;
  - nella risposta anche errno cURL, URL del redirect e IP;
  - **CA automatica**: php.ini, `php\extras\ssl\cacert.pem` di XAMPP, `apache\bin\curl-ca-bundle.crt`, oppure l'archivio certificati nativo di Windows. Così si evita l'errore cURL 60 su XAMPP.
- Impostazioni:
  - pulsante **Diagnostica**, eseguita anche automaticamente quando il test fallisce;
  - impronta del segreto da confrontare con il plugin;
  - plugin consigliato 1.1.1.
- Configurazione guidata (passo 3) e Sincronizzazione: dettaglio della diagnostica o link a essa quando il test fallisce.

## Plugin pm-ats 1.1.1
- Fix della doppia verifica.
- Errori con `data.reason`, messaggio leggibile e dati di diagnosi: `client_ip`, `server_time`, `route`, tentativi falliti.
- Intestazioni `X-PM-ATS-Version` e `X-PM-ATS-Error` anche sugli errori.
- Nella scheda Connessione: impronta del segreto e ultimo accesso rifiutato.
