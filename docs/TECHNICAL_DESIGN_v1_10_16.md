# TECHNICAL DESIGN — v1.10.16 · Handshake PortalManager → pm-ats

## Handshake
```
PortalManager (WpAtsClient)                                   WordPress (pm-ats)
 GET {base}/sync/status                                        WP_REST_Server::dispatch
   X-PM-Client, X-PM-Timestamp, X-PM-Nonce,                      └ permission_callback = PM_ATS_Auth::verify(req) ──┐ 1ª verifica
   X-PM-Signature = HMAC(secret, M\nROUTE\nTS\nNONCE\nsha256(body))   callback status()                              │
                                                               rest_post_dispatch                                   │
                                                                 ├ rest_send_allow_header → permission_callback ────┤ 2ª invocazione
                                                                 └ PM_ATS_Rest::stamp → X-PM-ATS-Version/-Error      │
 1.1.0: 2ª invocazione = nonce già usato → «replay» + fallimento → 20 → 429 per 15 min
 1.1.1: esito memorizzato per spl_object_hash(req) → nessun effetto collaterale
```

## Diagnostica (WpAtsDiag::run)
| Passo | Chiamata | Esito |
|---|---|---|
| 1 Configurazione | — | URL normalizzato, client, lunghezza e impronta del segreto (`sha256('pm-ats-fp|'.secret)[0..12]`), TLS, CA (origine), proxy |
| 2 DNS | `gethostbynamel` | IP risolti (passo saltato con proxy) |
| 3 Trasporto / TLS | GET non firmata della radice REST | errno cURL, redirect con URL suggerito, risposte di intermediari |
| 4 Plugin | GET non firmata `pm-ats/v1` (indice del namespace) | presenza e numero di rotte, `rest_no_route` |
| 5 Autenticazione | GET firmata `/sync/status` | codice effettivo (`X-PM-ATS-Error` / `data.reason`) e dati di diagnosi |
| 6 Orologio | intestazione `Date` | scarto in secondi (tolleranza ±300) |

Classificazione (`WpAtsClient::analyze`): `rete` · `tls` · `http` (3xx) · `plugin` (X-PM-ATS-* o codice `pm_ats_*`) · `wordpress` (`rest_*`) · `intermediario` (≥ 400 non JSON e senza X-PM-ATS-Version).

## CA (WpAtsClient::caBundle)
L'ordine di ricerca è:
1. File CA delle impostazioni.
2. `curl.cainfo` / `openssl.cafile` di php.ini.
3. Bundle accanto a PHP: `extras/ssl/cacert.pem`, `cacert.pem` e `../apache/bin/curl-ca-bundle.crt` (XAMPP).
4. Altrimenti, su Windows, `CURLSSLOPT_NATIVE_CA` (archivio certificati del sistema).

## Dati
`wp_ats_diag(id, created_at, ok, summary, steps_json, user_id)`: ultime 100 diagnostiche, senza segreti. Ogni diagnostica è anche una riga in `wp_ats_sync_log` (test, «Diagnostica: …»).
Plugin: errori `WP_Error('pm_ats_<code>', messaggio, {status, reason, plugin, failures, failures_max, client_ip | server_time | route})`. Il segreto e il client ID attesi non vengono mai restituiti.
