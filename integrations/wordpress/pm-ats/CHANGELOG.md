# PortalManager ATS (pm-ats) — CHANGELOG

Versioning semantico `MAJOR.MINOR.PATCH`. Allineati a ogni rilascio: header `Version` di `pm-ats.php`, `Stable tag` di
`readme.txt`, `PM_ATS_VERSION`, intestazioni `@version` di asset e template, questo file.

| Costante | Valore | Significato |
|---|---|---|
| PM_ATS_VERSION | 1.3.1 | versione del plugin |
| PM_ATS_API_VERSION | 1 | protocollo REST `pm-ats/v1` (cambia solo con modifiche incompatibili) |
| PM_ATS_DB_VERSION | 1 | schema tabelle `pm_ats_applications`, `pm_ats_log` |
| PM_ATS_SETTINGS_VERSION | 4 | schema dell'opzione `pm_ats_settings` |
| PM_ATS_TEMPLATE_VERSION | 1.3.0 | template sovrascrivibili dal tema |
| PM_ATS_MIN_PM | 1.10.20 | PortalManager minimo per tutte le funzioni |

## 1.3.1 — 2026-10-07 (PortalManager v1.10.20)
- **Pagina «Lavora con noi» come nell'esempio**:
  - a sinistra «Unisciti a WeTech's!», introduzione e «Posizioni Aperte» come **elenco con titolo cliccabile** (`<a>` alla scheda, tutta la casella cliccabile, freccia ›, sede · modalità · contratto sotto il titolo);
  - a destra «Compila il form» con il modulo di candidatura (scelta posizione o spontanea).
  - Il dettaglio a fisarmonica resta disponibile (`wt_list_mode = accordion`).
- **Layout predefinito «Lavora con noi»** (`layout = accordion`). Schema impostazioni 4: all'aggiornamento una griglia/lista rimasta dalla configurazione precedente passa una sola volta al nuovo layout, con riga nel registro; si può ripristinare in Impostazioni › Aspetto.
- Nuovo shortcode **`[pm_ats_lavora_con_noi]`**, indipendente dall'impostazione; attributi `hero="0|1"`, `elenco="link|accordion"`.
- **Colonne**:
  - il blocco occupa la larghezza del contenitore (riga o colonna Divi), senza forzare 100vw;
  - le due colonne si affiancano o impilano secondo la larghezza del contenitore (container query a 760 px) e dello schermo (980 px);
  - regole con priorità alta contro gli stili di colonna/lista del tema.
- Stili caricati nell'`<head>` di ogni pagina che contiene uno shortcode pm-ats.

## 1.3.0 — 2026-10-07 (PortalManager v1.10.19)
Due interventi separati:
- **Struttura vincolante della Job Description**, indipendente dal layout. Ordine: 1 Chi siamo (`presentation_text`) · 2 Informazioni sull'offerta (`offer_info`) · 3 Competenze (`required_skills`, `hard_skills`, `soft_skills` con sottotitoli se più d'uno) · 4 Costituisce titolo preferenziale (`nice_to_have`) · 5 Cosa offriamo (`we_offer`, `benefits`). Chiude la scheda la nota pari opportunità (`gender_disclaimer`).
  - Unica sorgente `PM_ATS_Jobs::STRUCTURE` / `sections()` / `sectionsHtml()`, usata da scheda, fisarmonica, anteprima, estratto e JSON-LD. `SECTIONS` è riordinata per i template del tema 1.0/1.1.
  - `description` (note interne di PortalManager: RAL, indicazioni operative) non è più pubblicata né conservata nel sito.
- **Layout di riferimento «Lavora con noi»** (`layout = accordion`), con il nuovo template `jobs-accordion.php` e il foglio `assets/pm-ats-wetechs.css`:
  - testata facoltativa (gradiente #00457a + immagine, titolo 60 px);
  - «Unisciti a {We}Tech's!» (48 px, #234d85, evidenza #ec7f31), introduzione, «Posizioni Aperte»;
  - fisarmonica: voci #f4f4f4, bordo #d9d9d9, raggio 10 px, titolo Montserrat 16 px bold, «+» / «×»;
  - riquadro modulo con sfondo d'accento, bordo #d06a27 e raggio 10 px; campi bianchi a 2 colonne, selezione della posizione e pulsante bianco/arancio;
  - responsive a 980 e 767 px. Classi proprie `.pm-ats-wt-*`, con la corrispondenza alle classi Divi documentata nel template. Nessun font esterno caricato.
  - `apply-form.php`: campo «Posizione per cui ti candidi» quando il modulo serve più posizioni; «Candidati per questa posizione» la preseleziona.
- Template aggiornati a `@version 1.3.0`: `job-single.php`, `apply-form.php`, `jobs-accordion.php` (nuovo). `jobs-list.php` e `job-card.php` invariati.

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
