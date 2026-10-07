# PortalManager ATS (pm-ats) — CHANGELOG

Versioning semantico `MAJOR.MINOR.PATCH`. Allineati a ogni rilascio: header `Version` di `pm-ats.php`, `Stable tag` di
`readme.txt`, `PM_ATS_VERSION`, intestazioni `@version` di asset e template, questo file.

| Costante | Valore | Significato |
|---|---|---|
| PM_ATS_VERSION | 1.1.0 | versione del plugin |
| PM_ATS_API_VERSION | 1 | protocollo REST `pm-ats/v1` (cambia solo con modifiche incompatibili) |
| PM_ATS_DB_VERSION | 1 | schema tabelle `pm_ats_applications`, `pm_ats_log` |
| PM_ATS_SETTINGS_VERSION | 2 | schema dell'opzione `pm_ats_settings` |
| PM_ATS_TEMPLATE_VERSION | 1.1.0 | template sovrascrivibili dal tema |
| PM_ATS_MIN_PM | 1.10.14 | PortalManager minimo per tutte le funzioni |

## 1.1.0 — 2026-10-07 (PortalManager v1.10.14)
- Configurazione guidata all'attivazione: requisiti, connessione, pagina «Lavora con noi», aspetto, verifica.
- Codice di connessione `PMATS1.…` (URL API, client ID, segreto) per la configurazione guidata di PortalManager.
- Impostazioni a schede con salvataggio parziale; scheda «Versione e manutenzione».
- Migrazioni automatiche (`PM_ATS_Upgrade::maybe`), storico versioni, avviso template del tema obsoleti.
- `X-PM-ATS-Version` su ogni risposta; `/sync/status` con versioni e stato onboarding.

## 1.0.0 — 2026-10-06 (PortalManager v1.10.05)
- Prima versione: posizioni aperte, candidature con CV, API HMAC, privacy, template.
