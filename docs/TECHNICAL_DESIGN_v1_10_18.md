# TECHNICAL DESIGN — v1.10.18 · Pubblicazione puntuale e anteprima

## Separazione delle responsabilità
```
STATO DI PUBBLICAZIONE                                   ANTEPRIMA
job_positions.web_status (publish|draft|off)             nessuno stato: lettura dei dati attuali
  │ wp_ats_sync.php action=web_status (edit)               │ wp_ats_sync.php ?preview=<id> (view)
  ▼                                                         ▼
WpAtsSync::pushOne(id) ── POST /sync/jobs {mode:delta,    WpAtsSync::preview(id) ── POST /sync/preview {item}
                           items:[item]|[], closed:[id]}     ▼ plugin: transient pm_ats_pv_<token> (30 min)
  ▼ plugin PM_ATS_Jobs::sync                               302 → <sito>/?pm_ats_preview=<token>  (solo host configurato)
  post_status = publish | draft ; _pm_web_status            │ PM_ATS_Public::preview: job-single.php + wp_head/wp_footer,
  assente/closed → draft + withdrawn                        │ modulo disattivato, noindex, 410 se scaduto
  ▼ map {id, post_id, url, status}                          └ errore → WpAtsPreview::html() (anteprima locale)
position_publications.status = published | draft | removed
```

## Regole
- Inviabile = presente in `v_public_open_positions` (aperta, avviata, non scaduta) **e** `web_status <> 'off'`.
- Invio completo: le posizioni assenti (chiuse, scadute, `off`) vengono ritirate. Le bozze restano bozze.
- `pushOne`: se la posizione è inviabile viene inviato l'item con il suo `web_status`, altrimenti `closed:[id]`. Registro aggiornato solo per quella posizione.
- Plugin, controllo «invariata»: oltre all'impronta del contenuto confronta anche `post_status` e `_pm_web_status`, quindi il cambio di stato viene applicato anche a contenuto identico.
- Elenco, filtri, conteggio e JSON-LD considerano solo `publish`. La pagina di una bozza dà 404 senza il reindirizzo «posizione chiusa».

## Sicurezza
- Il token di anteprima è di 48 caratteri hex casuali, monouso solo per scadenza, e non contiene dati.
- Il redirect di PortalManager va solo verso l'host dell'URL API configurato.
- Lo stato si cambia con il permesso `edit` su `wp_ats_sync.php`; l'anteprima richiede `view`. Ogni azione è registrata nell'event log (Recruiting).

## Schema
`job_positions`: `web_status ENUM('publish','draft','off') NOT NULL DEFAULT 'publish'`, `web_status_at DATETIME`, `web_status_by INT`.
