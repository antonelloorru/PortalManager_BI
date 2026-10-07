=== PortalManager ATS – Posizioni aperte & Candidature ===
Contributors: portalmanager
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.0
License: Proprietary

Pubblica sul sito le posizioni aperte gestite in PortalManager e raccoglie le candidature con CV.

== Descrizione ==

* La sincronizzazione è avviata da PortalManager (server aziendale): invia le posizioni e preleva le candidature.
  WordPress non chiama mai PortalManager e non serve aprire porte verso la rete aziendale.
* API REST firmate HMAC-SHA256 (client ID, timestamp ±5 min, nonce monouso, IP consentiti, blocco dopo ripetuti errori).
* Elenco posizioni con ricerca e filtri, scheda posizione con dati strutturati JobPosting (Google for Jobs),
  modulo di candidatura con upload CV (PDF/DOC/DOCX, verifica del contenuto), candidatura spontanea.
* CV in cartella privata con nome casuale; dopo l'import in PortalManager CV e dati non necessari vengono eliminati.
* Strumenti GDPR di WordPress (esportazione/cancellazione), conservazione configurabile, registro delle sincronizzazioni.
* Aspetto configurabile (colori, raggio, font, griglia/lista, CSS aggiuntivo) e template sovrascrivibili dal tema.

== Installazione ==

1. Plugin › Aggiungi nuovo › Carica plugin › pm-ats-1.1.0.zip › Attiva: si apre la **configurazione guidata**.
2. Requisiti → Connessione (client ID, IP consentiti, genera segreto) → copia il **codice di connessione** (PMATS1.…,
   mostrato una sola volta) → Pagina «Lavora con noi» (creata o scelta) → Aspetto → Verifica.
3. In PortalManager: Recruiting › Sito web › Configurazione guidata → incolla il codice → Test connessione.
4. Ogni impostazione resta modificabile in Lavora con noi › Impostazioni (schede Connessione, Pagina e modulo,
   Aspetto, Dati e conservazione, Versione e manutenzione).
In alternativa al segreto generato: define( 'PM_ATS_SECRET', '…64 caratteri…' ); in wp-config.php.

== Versioni ==

* Semver MAJOR.MINOR.PATCH: header «Version», «Stable tag», costante PM_ATS_VERSION, asset (?ver= e intestazione),
  template (@version) sono allineati a ogni rilascio.
* Protocollo API: pm-ats/v1 (PM_ATS_API_VERSION); cambia solo con modifiche incompatibili.
* Schema tabelle (PM_ATS_DB_VERSION) e impostazioni (PM_ATS_SETTINGS_VERSION): migrazioni automatiche e idempotenti
  al primo caricamento dopo l'aggiornamento; storico in Impostazioni › Versione e manutenzione.
* Compatibilità: plugin 1.1.x ↔ PortalManager ≥ 1.10.14 (wizard, controllo versioni); 1.0.x ↔ PortalManager ≥ 1.10.05.
* Ogni risposta API riporta X-PM-ATS-Version; GET /sync/status restituisce plugin, api, db, settings_schema, onboarding.

== Shortcode ==

[pm_ats_jobs]  [pm_ats_jobs layout="list" per_page="20" filters="0" spontaneous="0"]
[pm_ats_apply]  [pm_ats_apply job="ID"]  [pm_ats_count]

== Template ==

Copiare in wp-content/themes/<tema>/pm-ats/ uno o più file di templates/:
jobs-list.php, job-card.php, job-single.php, apply-form.php (non cambiare i name dei campi del modulo).

== API (per PortalManager) ==

GET  /wp-json/pm-ats/v1/sync/status
POST /wp-json/pm-ats/v1/sync/jobs                     {"mode":"full","items":[…],"closed":[]}
GET  /wp-json/pm-ats/v1/sync/applications?limit=20&after=0
GET  /wp-json/pm-ats/v1/sync/applications/{uuid}/cv
POST /wp-json/pm-ats/v1/sync/ack                      {"items":[{"uuid":"…","ok":true,"pm_candidate_id":1}]}

Firma: X-PM-Client, X-PM-Timestamp, X-PM-Nonce (32 hex),
X-PM-Signature = hex(HMAC-SHA256(segreto, METODO\nROTTA\nTIMESTAMP\nNONCE\nsha256(corpo))), ROTTA es. /pm-ats/v1/sync/jobs

== Upgrade Notice ==

= 1.1.0 =
Configurazione guidata, impostazioni a schede, codice di connessione per PortalManager, versioning formale.
Le installazioni 1.0.x già configurate non devono ripetere la configurazione.

== Changelog ==

= 1.1.0 =
* Configurazione guidata all'attivazione (requisiti, connessione, pagina, aspetto, verifica) — PortalManager v1.10.14.
* Codice di connessione (URL + client ID + segreto) da incollare in PortalManager.
* Impostazioni a schede con salvataggio per scheda; scheda «Versione e manutenzione»: versioni, storico aggiornamenti,
  template del tema da aggiornare, export/import impostazioni (JSON, senza segreto), ripristino, ripeti configurazione.
* Versioning formale: costanti di versione (plugin, API, DB, impostazioni, template), migrazioni automatiche,
  intestazione X-PM-ATS-Version, @version in asset e template.

= 1.0.0 =
* Prima versione (PortalManager v1.10.05).
