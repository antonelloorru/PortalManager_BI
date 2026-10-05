=== PortalManager ATS – Posizioni aperte & Candidature ===
Contributors: portalmanager
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
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

1. Plugin › Aggiungi nuovo › Carica plugin › pm-ats-1.0.0.zip › Attiva.
2. Lavora con noi › Impostazioni › Genera segreto: copiarlo (mostrato una sola volta) in PortalManager
   (Recruiting › Sito web (WordPress) › Segreto condiviso). In alternativa definirlo in wp-config.php:
   define( 'PM_ATS_SECRET', '…64 caratteri…' );
3. Indicare l'IP pubblico di uscita di PortalManager in «IP consentiti».
4. Inserire [pm_ats_jobs] nella pagina «Lavora con noi» e selezionarla in «Pagina elenco posizioni».
5. Indicare l'URL dell'informativa privacy.

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

== Changelog ==

= 1.0.0 =
* Prima versione (PortalManager v1.10.05).
