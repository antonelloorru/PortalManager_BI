# PortalManager v1.9.71 — Filtri, nominativi, export

## Correzione di una regressione (release v1.9.63–v1.9.68)
`app/MenuManager.php`, `app/Router.php`, `manage_permissions.php` erano stati ricostruiti
dallo snapshot anziché dalla versione precedente: con la v1.9.66/v1.9.68 sparivano dal menu,
dal router e dai permessi le voci **Import Pratix** (v1.9.59) e **Import certificazioni
Omnissa** (v1.9.63). Ora i tre file contengono tutte le voci (incluse SSO). La migration
ripristina i relativi permessi solo se assenti.

## Multi-select con ricerca — tutte le pagine
`assets/js/pm-multiselect.js` v2, caricato da `header.php`:
- ogni `<select multiple>`: ricerca (senza accenti), spunte, "Seleziona visibili"/"Nessuno",
  chip con conteggio, tastiera, select aggiunte dinamicamente;
- select dei form di filtro con più di 8 opzioni: ricerca, restano a scelta singola;
- la select nativa resta la fonte di verità (invio, `onchange`, reset);
- esclusione: `data-pm-ms="off"`.
Corretto un difetto della v1.9.30: forzava la multi-selezione su select a valore singolo
(`service_desk.php`: tecnico, coda, livello, gestione; `it_service.php`: ricavo), con il
server che riceveva solo l'ultima voce.

## Filtri più granulari — ListFilter
Filtri per colonna tipizzati automaticamente dai dati:
- elenco (valori brevi o ripetuti, ≤150 distinti): multi-selezione con ricerca e conteggi,
  valori vuoti raggruppati in "(vuoto)";
- numero: intervallo min/max (formato italiano, €, %, h);
- data: intervallo da/a (gg/mm/aaaa o aaaa-mm-gg);
- testo lungo: "contiene".
Le viste salvate in precedenza restano valide (un vecchio filtro "contiene" su una colonna
ora a elenco resta applicato e viene segnalato).

**Aggancio automatico**: le pagine con filtri server-side (32) ricevono ListFilter sulla
tabella risultati principale (visibile, con più righe, minimo 5) tramite `footer.php`.
Esclusi cruscotti, viste di stampa, tabelle annidate/in modali; opt-out con
`data-pm-nofilter` sulla tabella o `$GLOBALS['PM_NO_AUTOFILTER'] = true`.
Nota: dove i risultati sono paginati lato server i filtri agiscono sulla pagina visualizzata.

## Export CSV / XLSX / PDF / DOCX / ODT
Aggiunto **ODT** (OpenDocument) senza librerie, in `saved_views_api.php`: pacchetto ODF
conforme (mimetype prima voce non compressa), pagina orizzontale oltre 6 colonne.
Disponibile ovunque tramite ListFilter; esporta le righe filtrate (o tutte con l'opzione).

## Nominativi "Cognome Nome"
80 visualizzazioni in 44 file (PHP, SQL, JavaScript, notifiche, stampe, log, export).
Esclusi i confronti usati per abbinare i nominativi: `import_control.php`,
`app/DatasetSync.php`, `app/ProjectModel.php`, `cert_import_cisco.php`.
Nuovi helper in `functions.php`: `pm_fullname()`, `pm_fullname_sql()`.

## Filtri multi-valore lato server
Nuovo `app/PmFilter.php` (values/in/range/date/number/select) per convertire le query
delle pagine a valori multipli; accetta sia `x[]=a&x[]=b` sia `x=a,b`.

## QA
- Componente multi-select: 16/16 verifiche DOM.
- ListFilter: 20/20 (tipi colonna, filtri combinati, intervalli, reset, export righe
  filtrate, 5 formati) + 5 casi di esclusione dell'aggancio automatico.
- ODT: struttura ODF verificata, aperto da odfpy e convertito da LibreOffice.
- Helper: 12/12. `php -l` su 53 file, `node --check` sul JavaScript.
- Migration RUN1/RUN2 err=0, personalizzazioni dei permessi preservate; schema_version → 1.9.71.
