# Manuale Amministratore v1.10.05 — Sito web (WordPress)

## 1. Sul sito WordPress (amministratore del sito)
1. **Plugin › Aggiungi nuovo › Carica plugin** → `pm-ats-1.0.0.zip` → **Attiva**. Compare il menu **Lavora con noi**.
2. **Lavora con noi › Impostazioni › Connessione**
   - **Genera segreto** → copiare il valore (mostrato una sola volta). In alternativa, in `wp-config.php`: `define('PM_ATS_SECRET', '<64 caratteri>');`
   - **Client ID**: `portalmanager` (uguale in PortalManager).
   - **IP consentiti**: IP pubblico di uscita della rete aziendale (es. `203.0.113.10`). Vuoto = qualunque IP (sconsigliato).
   - **Origine IP client**: `REMOTE_ADDR`, salvo sito dietro Cloudflare/proxy affidabile.
   - Annotare **URL di base API** (es. `https://www.wetechs.it/wp-json/pm-ats/v1`).
3. **Pagina e modulo**: nella pagina «Lavora con noi» inserire lo shortcode `[pm_ats_jobs]` (blocco Shortcode / widget Shortcode di Elementor) e selezionarla in **Pagina elenco posizioni**. Impostare URL e versione dell'informativa privacy, formati e dimensione CV, email HR per le notifiche.
4. **Aspetto**: colore principale (predefinito `#ee7e02`), testo pulsanti, sfondi, bordi, arrotondamento, font (vuoto = tema), griglia/lista, CSS aggiuntivo.
5. **Dati e conservazione**: eliminazione dopo l'import (consigliato), giorni di conservazione, nome e logo azienda per Google for Jobs.
6. **Registro**: chiamate ricevute da PortalManager (nessun dato personale).
7. **Candidature**: stato di ciascuna candidatura (Da importare / In PortalManager / Errore import); «Riprova» rimette in coda; «Elimina» cancella dati e CV.

Personalizzazione grafica avanzata: copiare i file di `wp-content/plugins/pm-ats/templates/` in `wp-content/themes/<tema>/pm-ats/` e modificarli (senza cambiare i `name` dei campi del modulo).

## 2. In PortalManager (Super Admin)
**Recruiting & Agenzie › Sito web (WordPress) › Configurazione**
- **URL API del plugin**: quello annotato (basta anche `https://www.sito.it`). HTTPS obbligatorio.
- **Client ID** e **Segreto condiviso** (salvato in `.env.php` come `PM_WPATS_SECRET`, mai nel database).
- **Sincronizzazione attiva**, **Invia subito le posizioni a ogni modifica**, **Verifica certificato TLS**.
- Se compare «SSL certificate problem»: indicare **File CA** (es. `P:\xampp\apache\bin\curl-ca-bundle.crt`) o impostare `curl.cainfo` in `php.ini`.
- **Proxy in uscita** solo se la rete lo richiede.
Poi **Test connessione** → **Sincronizza tutto**.

## 3. Pianificazione (Windows)
```
schtasks /Create /SC MINUTE /MO 15 /TN "PortalManager - Sito web" /TR "\"P:\xampp\php\php.exe\" \"P:\xampp\htdocs\demo_portalmanager\cron_wp_ats.php\" --quiet" /RU SYSTEM
```
Opzioni: `--push`, `--pull`, `--test`, `--force` (anche con sincronizzazione disattivata). Uscita 0 ok · 1 errori · 2 configurazione.

## 4. Permessi
`wp_ats_sync.php`: concesso ai ruoli che vedono «Pubblica su portali» (Super Admin, HR Director, Direttore IT). `view` = consultazione; `edit` = avvio sincronizzazioni; configurazione solo Super Admin. Gestione in Amministrazione › Permessi.

## 5. Rotazione del segreto
Plugin: **Rigenera segreto** → PortalManager: incollare il nuovo valore e salvare. Fra i due passaggi le sincronizzazioni falliscono con «firma non valida».

## 6. Errori ricorrenti
| Messaggio | Causa / rimedio |
|---|---|
| firma non valida | segreto diverso fra sito e PortalManager |
| client ID diverso | allineare il Client ID |
| IP non consentito | IP pubblico cambiato: aggiornare «IP consentiti» |
| orologio fuori sincrono | sincronizzare l'ora del server (NTP) |
| troppi tentativi falliti | attendere 15 minuti dopo aver corretto la configurazione |
| plugin non attivo o URL errato | verificare plugin attivo e permalink (o URL con `?rest_route=`) |
| CV non valido / cv_bad_type | il file non corrisponde al formato dichiarato: candidatura segnata «Errore import» sul sito |

## 7. GDPR
Sul sito: Strumenti › Esporta/Cancella dati personali includono le candidature. Dopo l'import i dati sono in PortalManager: cancellazioni da gestire anche nel Dossier candidati.
