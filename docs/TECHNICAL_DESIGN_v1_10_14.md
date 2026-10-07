# TECHNICAL DESIGN — v1.10.14 · Integrazione pm-ats (WordPress ↔ PortalManager)

## Schema logico
```
 WORDPRESS (plugin pm-ats 1.1.0)                         PORTALMANAGER 1.10.14
 ┌──────────────────────────────────────┐                ┌────────────────────────────────────────┐
 │ Attivazione → redirect wizard        │                │ wp_ats_setup.php (wizard, Super Admin) │
 │ PM_ATS_Setup (5 passi)               │  codice        │  1 Prerequisiti (WpAtsConfig)          │
 │  1 Requisiti                          │  PMATS1.…      │  2 Connessione ← codice / manuale      │
 │  2 Connessione → segreto + codice ───┼───────────────►│     segreto → .env.php PM_WPATS_SECRET │
 │  3 Pagina e modulo                   │  (copia/incolla│  3 Verifica → GET /sync/status (HMAC)  │
 │  4 Aspetto                           │   una tantum)  │     compat: plugin ≥1.1.0, API v1      │
 │  5 Verifica (ultimo contatto PM)     │                │  4 Opzioni (rete, lotto, push)         │
 │ PM_ATS_Admin: Impostazioni a schede  │ ◄── HMAC ───── │  5 Avvio (push/pull, schtasks, attiva) │
 │ PM_ATS_Upgrade: versioni, storico    │  /sync/*       │ wp_ats_settings.php (impostazioni)     │
 │ REST /sync/status: plugin, api, db,  │ ───────────►   │ wp_ats_sync.php (operatività, registro)│
 │   settings_schema, min_pm, onboarding│  X-PM-ATS-     │ app_settings wpats.* (no segreto)      │
 └──────────────────────────────────────┘  Version       └────────────────────────────────────────┘
                VERSIONING / MANUTENZIONE: plugin 1.1.0 (API 1, DB 1, settings 2, template 1.1.0, PM min 1.10.14)
                                           PortalManager 1.10.14 (PLUGIN_MIN 1.1.0, PLUGIN_BASE 1.0.0, API 1)
```
La connessione parte sempre da PortalManager; il sito non chiama mai PortalManager.

## Moduli
| Modulo | Scopo |
|---|---|
| `pm-ats/includes/class-pm-ats-upgrade.php` | Versione installata, migrazioni idempotenti delle impostazioni, storico (20), stato onboarding, versioni dei template sovrascritti |
| `pm-ats/includes/class-pm-ats-setup.php` | Wizard (admin-post `pm_ats_setup`, nonce, `manage_options`), redirect all'attivazione, avviso, link azione |
| `pm-ats/includes/class-pm-ats-settings.php` | `TABS`, `merge/save` parziali, `connectionCode()`, `sanitize()` con `_tab` |
| `pm-ats/includes/class-pm-ats-admin.php` | Pagina impostazioni a schede, rotazione segreto, manutenzione (`admin-post pm_ats_maint`) |
| `app/WpAtsConfig.php` | `parseCode`, `save` (validazione HTTPS/CA/proxy/limiti, segreto in .env.php), `compat`, `test` (+ `wpats.remote_info`), `prerequisites`, `setupDone` |
| `wp_ats_setup.php` / `wp_ats_settings.php` | UI PortalManager (Super Admin, CSRF, PRG, event log `Recruiting`) |

## Codice di connessione
`PMATS1.` + base64url(JSON `{v:1, url, client, secret, plugin, api, site}`). Contiene il segreto: mostrato una sola volta (transient 120 s per utente), mai salvato in chiaro. In PortalManager il segreto va solo in `.env.php`.

## Compatibilità (`WpAtsConfig::compat`)
| Condizione | Esito |
|---|---|
| API ≠ 1 | ko (sincronizzazione bloccata) |
| plugin < 1.0.0 o assente | ko |
| 1.0.0 ≤ plugin < 1.1.0 | warn (sincronizzazione base) |
| plugin ≥ 1.1.0, onboarding ≠ done | warn |
| plugin ≥ 1.1.0 | ok |

## Dati (app_settings)
| Chiave | Valore |
|---|---|
| `wpats.setup_done` | 0/1 (1 alla prima migrazione se URL già presente) |
| `wpats.setup_at` | data completamento |
| `wpats.setup_step` | ultimo passo raggiunto (1-5) |
| `wpats.remote_info` | JSON plugin/api/wordpress/php/site/onboarding/compat/checked_at |

Opzioni WordPress: `pm_ats_version`, `pm_ats_version_history`, `pm_ats_settings_version`, `pm_ats_onboarding` (rimosse da `uninstall.php`). Nessuna modifica alle tabelle.

## Permessi
`wp_ats_setup.php` e `wp_ats_settings.php`: solo Super Admin (controllo in pagina, nessuna concessione ad altri ruoli). `wp_ats_sync.php` invariato (view/edit).
