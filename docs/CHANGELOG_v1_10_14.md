# CHANGELOG — v1.10.14 (2026-10-07)

Software 1.10.14 · Schema 1.10.14 · Upgrade `sql/migration_v1_10_14.sql` (pacchetto cumulativo da 1.10.06) · Plugin WordPress pm-ats **1.1.0**

## Schema logico

| Ambito | Requisito | Implementazione |
|---|---|---|
| **WordPress** | Wizard di onboarding / pre-attivazione | `Lavora con noi › Configurazione guidata` (5 passi: Requisiti → Connessione → Pagina e modulo → Aspetto → Verifica), redirect all'attivazione, avviso finché non completata |
| **WordPress** | Pagina admin impostazioni manuali | `Lavora con noi › Impostazioni` a schede (Connessione, Pagina e modulo, Aspetto, Dati e conservazione, Versione e manutenzione), salvataggio per scheda |
| **WordPress** | Versioning formale | Header `Version: 1.1.0` = `PM_ATS_VERSION`; costanti API/DB/impostazioni/template/PM minimo; `@version` in CSS/JS/template; readme `Stable tag`, `CHANGELOG.md` |
| **PortalManager** | Wizard connessione | `wp_ats_setup.php` (5 passi: Prerequisiti → Connessione → Verifica → Opzioni → Avvio) |
| **PortalManager** | Pagina impostazioni | `wp_ats_settings.php` (Connessione, Rete, Sincronizzazione, Versioni e compatibilità, Test, Pianificazione) |
| **Versioning / manutenzione** | Compatibilità, storico, migrazioni | Codice di connessione `PMATS1.`, controllo plugin ≥ 1.1.0 / API v1 da PortalManager, `PM_ATS_MIN_PM` dal plugin, storico aggiornamenti, migrazioni impostazioni idempotenti, export/import senza segreto, template sovrascritti obsoleti |

## Plugin WordPress pm-ats 1.1.0
- **Configurazione guidata**: requisiti (PHP, WordPress, permalink, HTTPS, OpenSSL, REST), connessione (client ID, IP consentiti, generazione segreto + **codice di connessione** mostrato una sola volta), pagina «Lavora con noi» creata con lo shortcode, privacy e notifiche, aspetto, verifica finale con riepilogo e ultimo contatto da PortalManager.
- **Impostazioni a schede** con salvataggio parziale (le caselle delle altre schede restano invariate); rigenerazione segreto con codice di connessione.
- **Versione e manutenzione**: tabella versioni, storico aggiornamenti, template sovrascritti dal tema (obsoleti evidenziati), export/import impostazioni JSON **senza segreto**, ripristino predefiniti (connessione e pagina conservate), ripetizione wizard.
- **Upgrade automatico** 1.0.0 → 1.1.0 (`PM_ATS_Upgrade::maybe`): nuove chiavi con valori predefiniti, installazioni già collegate marcate «configurazione completata».
- REST `/sync/status`: `api`, `db`, `settings_schema`, `min_pm`, `onboarding`, `php`; header `X-PM-ATS-Version`.

## PortalManager
- **Configurazione guidata** (`wp_ats_setup.php`, Super Admin): prerequisiti, codice di connessione o inserimento manuale, test firmato con compatibilità di versione, opzioni rete/sincronizzazione, primo invio posizioni / prelievo candidature, comando di pianificazione, attivazione.
- **Impostazioni** (`wp_ats_settings.php`, Super Admin): modifica manuale, compilazione da codice, rimozione segreto, test, versioni e compatibilità, riavvio wizard.
- **Sincronizzazione** (`wp_ats_sync.php`): configurazione spostata nelle due pagine; banner «Avvia la configurazione guidata»; avviso di compatibilità.
- `app/WpAtsConfig.php`: parsing del codice, salvataggio validato, compatibilità, prerequisiti, stato wizard.
- Il segreto resta **solo** in `.env.php` (`PM_WPATS_SECRET`).
