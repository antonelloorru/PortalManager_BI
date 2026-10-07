# PortalManager ATS (pm-ats) — CHANGELOG

Versioning semantico `MAJOR.MINOR.PATCH`. Allineati a ogni rilascio: header `Version` di `pm-ats.php`, `Stable tag` di
`readme.txt`, `PM_ATS_VERSION`, intestazioni `@version` di asset e template, questo file.

| Costante | Valore | Significato |
|---|---|---|
| PM_ATS_VERSION | 1.2.0 | versione del plugin |
| PM_ATS_API_VERSION | 1 | protocollo REST `pm-ats/v1` (cambia solo con modifiche incompatibili) |
| PM_ATS_DB_VERSION | 1 | schema tabelle `pm_ats_applications`, `pm_ats_log` |
| PM_ATS_SETTINGS_VERSION | 2 | schema dell'opzione `pm_ats_settings` |
| PM_ATS_TEMPLATE_VERSION | 1.1.0 | template sovrascrivibili dal tema |
| PM_ATS_MIN_PM | 1.10.18 | PortalManager minimo per tutte le funzioni |

## 1.2.0 — 2026-10-07 (PortalManager v1.10.18)
- **Pubblicazione puntuale**: `/sync/jobs` accetta `web_status` per item. `publish` = visibile (predefinito, compatibile con PortalManager precedenti). `draft` = bozza: il post esiste ma non è visibile né elencato, e l'URL dà 404 invece del messaggio «posizione chiusa». Le posizioni escluse da PortalManager vengono ritirate. Meta `_pm_web_status` = publish | draft | withdrawn.
- **Anteprima**: `POST /sync/preview` (firmata) → token casuale valido 30 minuti (transient). `?pm_ats_preview=<token>` mostra la scheda con il template `job-single`, gli stili del plugin e del tema e i colori, con modulo disattivato, `noindex` e barra «ANTEPRIMA» con lo stato sul sito. Non crea né modifica contenuti.
- API invariata (`pm-ats/v1`), nuova rotta additiva. Template invariati (`@version 1.1.0`).

## 1.1.1 — 2026-10-07 (PortalManager v1.10.16)
- **Fix handshake**: WordPress richiama il `permission_callback` anche in `rest_send_allow_header` (intestazione `Allow`), quindi la verifica HMAC girava due volte per richiesta. La seconda trovava il nonce già usato: registrava «replay» e incrementava il contatore dei fallimenti anche sulle chiamate riuscite. Dopo 20 chiamate in 15 minuti scattava il blocco `429 too_many_failures` e test e sincronizzazioni fallivano. Gli errori reali erano contati due volte. Ora l'esito è memorizzato per richiesta.
- Errori di autenticazione con `data.reason`, messaggio leggibile e dati di diagnosi: `client_ip` (ip_not_allowed), `server_time` (clock_skew), `route` (bad_signature), tentativi falliti.
- `X-PM-ATS-Version` e `X-PM-ATS-Error` su tutte le risposte del namespace, errori compresi.
- Impronta del segreto (`PM_ATS_Settings::fingerprint`) e ultimo accesso rifiutato nella scheda Connessione.
- Asset e template invariati (`@version 1.1.0`).

## 1.1.0 — 2026-10-07 (PortalManager v1.10.14)
- Configurazione guidata all'attivazione: requisiti, connessione, pagina «Lavora con noi», aspetto, verifica.
- Codice di connessione `PMATS1.…` (URL API, client ID, segreto) per la configurazione guidata di PortalManager.
- Impostazioni a schede con salvataggio parziale; scheda «Versione e manutenzione».
- Migrazioni automatiche (`PM_ATS_Upgrade::maybe`), storico versioni, avviso template del tema obsoleti.
- `X-PM-ATS-Version` su ogni risposta; `/sync/status` con versioni e stato onboarding.

## 1.0.0 — 2026-10-06 (PortalManager v1.10.05)
- Prima versione: posizioni aperte, candidature con CV, API HMAC, privacy, template.
