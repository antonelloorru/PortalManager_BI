=== PortalManager ATS – Posizioni aperte & Candidature ===
Contributors: portalmanager
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.3.1
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

= 1.3.1 =
La pagina posizioni passa al layout «Lavora con noi»: elenco con titoli cliccabili a sinistra e modulo di candidatura a destra. Nuovo shortcode [pm_ats_lavora_con_noi].

= 1.3.0 =
Ordine vincolante delle sezioni della Job Description e nuovo layout «Fisarmonica con modulo a lato» (Lavora con noi). Le copie dei template nel tema vanno aggiornate (job-single.php, apply-form.php).

= 1.2.0 =
Pubblicazione puntuale (pubblicata / bozza / ritirata) decisa in PortalManager per ogni posizione e anteprima della scheda annuncio. Richiede PortalManager 1.10.18 per le nuove funzioni.

= 1.1.1 =
Corregge il blocco 429 dopo 20 chiamate riuscite (verifica HMAC eseguita due volte). Diagnostica della connessione: codice d'errore effettivo, IP visto dal sito, ora del sito, impronta del segreto.

= 1.1.0 =
Configurazione guidata, impostazioni a schede, codice di connessione per PortalManager, versioning formale.
Le installazioni 1.0.x già configurate non devono ripetere la configurazione.

== Changelog ==

= 1.3.1 =
* Layout «Lavora con noi»: elenco delle posizioni con titolo cliccabile che apre la scheda, modulo di candidatura nella colonna destra.
* Layout predefinito «Lavora con noi»; all'aggiornamento sostituisce una sola volta griglia/lista (ripristinabili in Impostazioni › Aspetto).
* Shortcode [pm_ats_lavora_con_noi] (attributi hero="0|1", elenco="link|accordion").
* Due colonne secondo la larghezza del contenitore (riga/colonna Divi), senza forzare la larghezza dello schermo.

= 1.3.0 =
* Struttura vincolante della Job Description: Chi siamo, Informazioni sull'offerta, Competenze, Costituisce titolo preferenziale, Cosa offriamo (PM_ATS_Jobs::STRUCTURE / sections() / sectionsHtml()) su scheda, elenco, anteprima, estratto e dati strutturati.
* Il campo «description» di PortalManager (note interne) non viene più pubblicato né conservato.
* Layout «Fisarmonica con modulo a lato» che replica la pagina Lavora con noi: testata facoltativa, titolo con evidenza, posizioni a fisarmonica, modulo nel riquadro d'accento con scelta della posizione.
* Nuove impostazioni Aspetto: colori titoli/accento, testata, titoli e introduzione.

= 1.2.0 =
* Stato di pubblicazione per posizione (web_status publish | draft) da PortalManager; le bozze non sono visibili e non vengono segnalate come «chiuse».
* Anteprima della scheda: POST /sync/preview → URL temporaneo (30 min, noindex) con la stessa resa della pagina pubblicata, modulo disattivato.

= 1.1.1 =
* Fix: verifica HMAC eseguita due volte per richiesta (permission_callback richiamato da rest_send_allow_header) → falsi «replay» e blocco 429 dopo 20 chiamate; ora una sola verifica per richiesta — PortalManager v1.10.16.
* Errori di autenticazione con codice e dati di diagnosi (IP visto, ora del sito, rotta firmata) e intestazione X-PM-ATS-Error.
* X-PM-ATS-Version anche sugli errori: PortalManager distingue un rifiuto del plugin da uno di firewall/CDN.
* Impronta del segreto e ultimo accesso rifiutato in Impostazioni › Connessione.

= 1.1.0 =
* Configurazione guidata all'attivazione (requisiti, connessione, pagina, aspetto, verifica) — PortalManager v1.10.14.
* Codice di connessione (URL + client ID + segreto) da incollare in PortalManager.
* Impostazioni a schede con salvataggio per scheda; scheda «Versione e manutenzione»: versioni, storico aggiornamenti,
  template del tema da aggiornare, export/import impostazioni (JSON, senza segreto), ripristino, ripeti configurazione.
* Versioning formale: costanti di versione (plugin, API, DB, impostazioni, template), migrazioni automatiche,
  intestazione X-PM-ATS-Version, @version in asset e template.

= 1.0.0 =
* Prima versione (PortalManager v1.10.05).
