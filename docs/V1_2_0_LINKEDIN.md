# PortalManager 1.2.0 — Integrazione LinkedIn

## Sintesi

Importazione e sincronizzazione di **certificazioni** e **curriculum** dei dipendenti dal loro profilo LinkedIn.

## Perché upload e non scraping

LinkedIn vieta lo scraping HTTP automatizzato dei profili **anche pubblici**, come stabilito dal contenzioso *hiQ Labs v. LinkedIn* (2017–2022, conclusosi con permanent injunction contro lo scraper). Implementare uno scraper diretto da `linkedin.com/in/<vanity>` esporrebbe l'azienda a:

- Rischio legale (breach of User Agreement)
- Blocco IP del server da parte di LinkedIn
- Violazione potenziale GDPR (trattamento dati senza base giuridica chiara)

La soluzione conforme è l'**upload dell'export ufficiale** che il dipendente ottiene dal proprio account — è un dato che l'interessato fornisce volontariamente, base giuridica solida.

## Metodi di import supportati

### 1. Archivio dati ZIP (consigliato)

Il dipendente su LinkedIn:
1. `Impostazioni e Privacy` → `Privacy dei dati` → `Ottieni una copia dei tuoi dati`
2. Seleziona *"Vuoi qualcosa in particolare"* → spunta almeno **Certifications**, **Profile**, **Positions**, **Skills**
3. `Richiedi archivio` → pronto in ~10 minuti, arriva via email come `.zip`

L'importer parsa i CSV interni: `Certifications.csv`, `Profile.csv`, `Positions.csv`, `Education.csv`, `Skills.csv`, `Languages.csv`.

### 2. PDF del profilo

Il dipendente sul proprio profilo: `Altro` → `Salva come PDF`. L'importer estrae le sezioni *Licenses & certifications*, *Summary*, *Skills* tramite parser euristico (usa `pdftotext` se disponibile sul server, altrimenti fallback PHP puro). Il PDF viene anche salvato come allegato CV del dipendente.

### 3. Singolo CSV

Caricamento diretto di `Certifications.csv` o `Profile.csv` se già estratti.

## Cosa viene importato

| Sorgente dati | Destinazione PortalManager |
|---|---|
| Certifications.csv | `user_certifications` (+ auto-creazione `certifications` se non a catalogo) |
| Authority del badge | `brands` (auto-creato se mancante) |
| Profile.csv → Headline + Summary | `employees.bio` (merge non distruttivo) |
| Positions.csv | Cronologia esperienze appesa a `employees.bio` |
| Skills.csv | `employees.technical_skills` (merge, no duplicati) |
| PDF profilo | `employees.cv_path` (allegato salvato in `uploads/cv_dipendenti/`) |

### Politica merge CV

L'aggiornamento di `bio` e `technical_skills` è **non distruttivo**:
- `bio` viene aggiornata solo se vuota o se la versione LinkedIn è significativamente più completa
- `technical_skills` fa merge additivo (unione, nessun duplicato), mai sovrascrittura

## Auto-creazione catalogo

Come per Credly, le certificazioni LinkedIn non presenti nel catalogo vengono **create automaticamente** (controllato da `linkedin_auto_create_catalog`, default `1`):

- **Brand**: l'Authority del badge (es. "Microsoft", "Cisco", "AWS") → creato come brand `partnership_level='Registered'` se non esiste
- **Technology**: assegnata al placeholder "Generale" (da riassegnare manualmente dopo)
- **Certification**: INSERT con nome, codice slug, level dedotto dal nome, URL credenziale

## Stati esito import

| Esito | Significato |
|---|---|
| `imported` | Catalogo aveva la cert → `user_certifications` creato |
| `created_cert` | Catalogo non aveva la cert → catalogo + collegamento creati |
| `updated` | Esistente, aggiornate date/scadenze |
| `unchanged` | Nessuna differenza |
| `unmatched` | Solo se `linkedin_auto_create_catalog=0` |
| `error` | Errore tecnico (logged) |

## Tabella `employee_linkedin_link`

| Colonna | Tipo | Note |
|---|---|---|
| `employee_id` | INT PK | FK employees.id |
| `linkedin_vanity` | VARCHAR(150) | Vanity LinkedIn (es. `a-orru750122`) |
| `last_sync_at` | DATETIME | Timestamp ultimo import |
| `last_sync_imported/updated/unmatched` | INT | Contatori ultimo import |
| `created_by`, `created_at`, `updated_at` | — | Audit |

## Visualizzazione nella scheda dipendente

`user_profile.php` distingue ora **tre fonti** di certificazione:

- **Manuale** — icona penna celeste
- **Credly** — scudo viola, pill cliccabile al badge pubblico
- **LinkedIn** — icona LinkedIn blu, pill cliccabile al profilo/credenziale

Elementi UI:
- Badge contatore nel titolo: `N Credly` + `N LinkedIn`
- Filtro tab: Tutte / Manuali / Credly / LinkedIn (JS lato client)
- Mini-badge nel KPI "Cert. attive"
- Riga con bordo colorato per fonte (viola Credly, blu LinkedIn)
- Colonna "Verifica" con link diretto alla fonte pubblica

## App settings

| Chiave | Default | Effetto |
|---|---|---|
| `linkedin_enabled` | `1` | Master switch funzionalità |
| `linkedin_auto_create_catalog` | `1` | Auto-crea brand+cert per badge sconosciuti |
| `linkedin_update_cv` | `1` | Aggiorna bio/skills/CV durante import |

## Sicurezza

- **Accesso UI**: Super Admin (1) e HR Director (2)
- **Upload**: validazione MIME + estensione + dimensione (max 25 MB)
- **File temporaneo** processato e rimosso (`finally` block)
- **Audit**: ogni mutazione in `entity_change_log` con `source='linkedin'`
- **Nessuna chiamata HTTP** verso LinkedIn → zero rischio scraping/ban

## Procedura installazione

```powershell
cd C:\xampp\htdocs\portalbrand
Expand-Archive -Path "PortalManager-1.2.0-linkedin.zip" -DestinationPath . -Force
```

phpMyAdmin → Importa → `sql/migration_v1_2_0.sql`

Voce **Import LinkedIn** comparirà nel menu sotto "Import Credly".

### Dipendenza opzionale: pdftotext

Per un parsing PDF più affidabile, installare poppler-utils:
- **Windows**: scaricare poppler per Windows, aggiungere `bin/` al PATH
- **Linux**: `apt install poppler-utils`

Se assente, l'importer usa un fallback PHP puro (meno preciso, ma funzionante per PDF testuali). Lo ZIP CSV non richiede pdftotext.

## Test con il profilo fornito

Per Antonello Orrù (`https://www.linkedin.com/in/a-orru750122`):

1. Login Super Admin → menu **Import LinkedIn**
2. Click **Collega** sul dipendente Antonello Orrù
3. Incolla `https://www.linkedin.com/in/a-orru750122` → Salva
4. Il dipendente esegue l'export dal proprio LinkedIn e fornisce il file ZIP/PDF
5. Click **Importa** → carica il file → esito dettagliato per ogni cert/skill
6. Apri la scheda dipendente: le cert LinkedIn appaiono con icona blu e link al profilo

## File pacchetto

- `app/LinkedInImporter.php` (NUOVO) — parser ZIP/PDF/CSV + import + auto-catalogo
- `app/Router.php` — whitelist slug `linkedin_sync`
- `linkedin_sync.php` (NUOVO) — UI pagina admin con upload
- `user_profile.php` — visualizzazione cert LinkedIn nella scheda dipendente
- `header.php` — voce menu sotto "Import Credly"
- `sql/migration_v1_2_0.sql` — tabella + RBAC + settings + bump versione
- `docs/V1_2_0_LINKEDIN.md` — questa documentazione
