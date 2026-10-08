# TECHNICAL DESIGN — v1.10.24 · Gestione Commesse › Ricerca

## Componenti
| File | Ruolo |
|---|---|
| `app/CmSearch.php` | Registro degli ambiti, parsing dei parametri, condizioni, interrogazioni, formattazione, report per l'export |
| `cm_search.php` | Pagina: tab degli ambiti, filtri, tabella, paginazione, export (prima di `header.php`) |
| `app/PmReport.php` (esistente) | Resa del report in DOCX (DocxWriter), PDF (PdfWriter), XLSX (XlsxWriter), CSV |
| `app/Router.php`, `app/MenuManager.php`, `app/PermissionCatalog.php` | Slug opaco, voce di menu, matrice permessi |

## Registro degli ambiti (`CmSearch::registry()`)
Ogni ambito definisce:
- `from` — tabella principale e LEFT JOIN;
- `where` — condizione di base, ad esempio `da.deleted = 0` per DGB;
- `key` — chiave per un ordinamento stabile;
- `sort` — ordinamento predefinito;
- `date` (colonna) oppure `period` (inizio, fine): campo usato dal filtro dal/al;
- `rel` — espressioni dei filtri di correlazione (`commessa`, `cliente`, `persona`: LIKE in OR; `societa`: uguaglianza);
- `links` — colonna → pagina e colonna di servizio con l'id;
- `gate` — pagine sorgente;
- `cols` — colonne.

Ogni colonna ha: `l` etichetta, `x` espressione SQL, `t` tipo, `f` flag.
- Tipi: `text`, `long`, `int`, `num`, `hours`, `eur`, `date`, `datetime`, `bool`.
- Flag: `d` predefinita, `e` economica, `s` sommabile, `h` di servizio.

### Correlazioni ereditate dal modulo
- **Commessa**: `cm_projects.id = *.project_id`. Se manca, si usa il codice grezzo (`COALESCE(p.project_code, r.project_code)`).
- **Cliente**: `COALESCE(clients.name, *.client_raw)`. Nei moduli il cliente è `COALESCE(r.client_id, p.client_id)`.
- **Tecnico dei moduli**: dipendente (`technician_id`), poi professionista (`technician_professional_id`), poi `technician_raw`, nella forma «Cognome Nome» come in `v_cm_nomi`.
- **DGB**: `cm_projects.dgb_contract_id = dgb_forms_activity.id_contract`, attraverso una tabella derivata `MIN(id) GROUP BY dgb_contract_id` per non duplicare righe. Solo `deleted = 0`, come `DgbModel`.
- **Pratix**: commesse da `cm_project_operations.order_code`, come `v_cm_pratix_righe` e `v_cm_pratix_commessa_codici`.
- **PRJ**: `cm_prj.sp_project_id = cm_projects.id`.
- **Aggregati** di commesse e professionisti: tabelle derivate su `cm_intervention_reports` raggruppate per `project_id` o per `technician_professional_id`.

## Flusso
1. `CmSearch::params($_GET, $def)` normalizza i parametri:
   - lunghezze massime;
   - date ISO;
   - `per` ∈ {25, 50, 100, 250};
   - colonne, filtri e ordinamento accettati solo se la chiave è tra le colonne visibili dell'ambito, quindi già senza le colonne economiche se manca il permesso.
2. `where()` compone le condizioni in AND:
   - base;
   - testo libero (OR su tutte le colonne `text`/`long`);
   - correlazioni;
   - società;
   - periodo;
   - filtri di colonna.
   I filtri non validi finiscono in `$bad` e la pagina li segnala. In «Tutto il database» (`strictRel`) un ambito che non supporta un filtro richiesto viene saltato.
3. `summary()`: `COUNT(*)` e `SUM()` delle colonne `s` visibili, in una sola query.
4. `rows()`:
   - colonne scelte più quelle di servizio;
   - `ORDER BY (expr) IS NULL, expr DIR, key DIR`, quindi i NULL vanno in fondo e l'ordine è stabile;
   - `LIMIT`/`OFFSET` interi.
5. Export, con `report()`:
   - righe fino a `MAX_FILE` (50.000) oppure `MAX_DOC` (3.000);
   - valori tipizzati con `value()`: date `d/m/Y`, sì/no, numeri arrotondati a 2 decimali;
   - riga totale;
   - `PmReport` orizzontale con meta (filtri, righe, ordinamento) e avvisi di troncamento;
   - `send()`.

## Sintassi dei filtri di colonna (`CmSearch::cond`)
| Tipo | Valori |
|---|---|
| testo | `abc` (LIKE), `a\|b`, `=abc`, `^abc`, `!abc`; `%` e `_` vengono neutralizzati |
| numeri | `10`, `>10`, `>=10`, `<10`, `<=10`, `!=10`, `10..20`; decimali IT o EN |
| date | `2026`, `2026-03`, `03/2026`, `2026-03-15`, `15/03/2026`, `>=…`, `<=…`, `>…`, `<…`, `a..b` (periodi interi); `DATE()` sui datetime |
| sì/no | `sì`/`si`/`1`, `no`/`0` |
| tutti | `=` vuoto/NULL, `!=` valorizzato |

## Permessi
| Permesso | Effetto | Assegnazione iniziale (migrazione) |
|---|---|---|
| `cm_search.php` view / export | pagina ed export | ruoli con view su almeno una pagina sorgente; export = massimo `can_export` su quelle pagine |
| `cm_search_economics.php` view | colonne con flag `e` | ruoli con view su `dir_report.php`, più Super Admin |
| pagine sorgente (`gate`) | visibilità del singolo ambito | invariati |

Inserimenti con `INSERT IGNORE`: i permessi già personalizzati restano invariati.

## Prestazioni (pmrepo, ~73k moduli, ~109k allocazioni)
| Operazione | Tempo |
|---|---|
| Pagina di un ambito senza filtri (conteggio + 50 righe) | 0,06–0,14 s |
| Testo libero sui moduli | ~0,8 s |
| «Tutto il database» con testo | ~1,2 s |
| XLSX da 50.000 righe | ~2,7 s, ~130 MB di picco |

Durante l'export: `set_time_limit(300)`, `memory_limit` 1024M.
